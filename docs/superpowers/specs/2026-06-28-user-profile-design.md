# User Profile (Livewire + Filament) — Design

**Date:** 2026-06-28
**Branch:** feat/implement-livewire-for-loans
**Status:** Approved

## Goal

Allow any authenticated user to manage their own profile: change email, change
password, and upload/remove a profile photo. Deliver on both surfaces — the
custom Livewire frontend (primary app) and the Filament admin panel.

## Decisions (locked)

- **Current password required** when changing email *and* when changing password.
- **Email re-verification on change**: setting a new email resets
  `email_verified_at` and sends a fresh verification link.
- **Filament**: use the built-in `->profile()` page extended with an avatar
  field; enable panel email verification.
- Editing **name** is included (avatar initials derive from it; trivial cost).

## Known constraint

`MAIL_MAILER=log` — verification emails are written to
`storage/logs/laravel.log`, not delivered, until real SMTP is configured. The
mechanism works regardless; delivery is a separate ops task.

## Architecture

### 1. Data & Model

- **Migration** `add_avatar_path_to_users_table`: `avatar_path` string, nullable,
  after `email`.
- **User model** (`app/Models/User.php`):
  - `implements MustVerifyEmail, HasAvatar` (Filament contract).
  - `use Illuminate\Auth\MustVerifyEmail` trait.
  - add `avatar_path` to `$fillable`.
  - `avatarUrl(): ?string` — `Storage::disk('public')->url($avatar_path)` or null.
  - `getFilamentAvatarUrl(): ?string` — returns `avatarUrl()`.
- Verification is **not** enforced as a hard gate (no `verified` middleware on
  routes or panel) so existing users with null `email_verified_at` are not
  locked out. Email change only resets the flag and re-sends the link; an
  "unverified" badge is shown until confirmed.

### 2. Livewire profile page (frontend)

- Component: `App\Livewire\Profile\EditProfile`
- View: `resources/views/livewire/profile/edit-profile.blade.php`
- Route: `GET /profil` → name `profile.edit`, `auth` middleware only (no
  permission gate — users edit their own profile).
- Single page, three cards, using existing design tokens (Plus Jakarta Sans,
  `bg-surface`, `border-border`, `text-muted`, primary/secondary, dark mode):
  1. **Foto Profil** — avatar/initials preview, upload via `WithFileUploads`
     (mimes jpg/png/webp, max 2 MB), live temporary preview, "Simpan Foto" and
     "Hapus Foto". Old file deleted on replace/remove. Stored on `public` disk
     under `avatars/`.
  2. **Informasi Akun** — `name` + `email`. Changing email requires
     `current_password`; on change → `email_verified_at = null`, send
     verification, flash success. Shows verification status badge + "Kirim ulang
     link verifikasi" button.
  3. **Ubah Password** — `current_password` + `password` (confirmed, Laravel
     `Password::defaults()` rules). Clears fields on success.
- Feedback via session flash / Livewire dispatch consistent with existing
  components.

### 3. Email verification routes

Added to the `auth` group in `routes/web.php`:
- `verification.verify` — signed + auth, calls `fulfill()` / marks verified,
  redirects back to profile with flash.
- `verification.notice` — informational, redirects to profile.

### 4. Filament side

- `App\Filament\Pages\Auth\EditProfile extends Filament\Pages\Auth\EditProfile`
  adding an avatar `FileUpload` (`avatar_path`, image, public disk, `avatars/`
  directory, avatar/circle cropper) to the built-in name/email/password form.
- `AdminPanelProvider`: `->profile(EditProfile::class)`.
- **Not** using the panel's `->emailVerification()` — it gates panel access
  behind verification and would lock out the existing unverified user (mail is
  `log`). Instead the page overrides `handleRecordUpdate()`: on email change it
  nulls `email_verified_at` and calls `sendEmailVerificationNotification()`. The
  notification's link targets the global `verification.verify` route defined in
  `web.php`, so a single verification flow serves both surfaces.

### 5. Layout integration

- Sidebar footer user block in `resources/views/components/layouts/app.blade.php`
  becomes a link to `profile.edit`, rendering the photo when `avatar_path` is set
  and falling back to the existing initials avatar.

## Testing (Pest)

- Password update succeeds with correct current password; fails with wrong one.
- Email change resets `email_verified_at` and sends verification notification
  (`Notification::fake()`).
- Avatar upload stores file on `public` disk and sets `avatar_path`
  (`Storage::fake()`).
- Avatar removal deletes the file and nulls the column.
- Validation failures: bad image type/size, mismatched password confirmation,
  taken email.

## Out of scope

- Forcing email verification as an access gate.
- Configuring real SMTP delivery.
- Two-factor auth, account deletion, session management.
