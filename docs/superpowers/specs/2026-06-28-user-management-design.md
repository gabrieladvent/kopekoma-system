# Desain: Menu User Management (Filament)

**Tanggal:** 2026-06-28
**Branch:** `feat/user-management` (dari `development`)
**Status:** Approved (brainstorming)

## Tujuan

Menyediakan menu **User** di Filament admin panel sehingga admin (super_admin) dapat:
menambah user, mengedit, menghapus, dan mengatur role user. Mengikuti konvensi
Filament Resource KOPEKOMA (lihat memory `filament-resource-conventions`).

## Keputusan (hasil brainstorming)

1. **Multi-role** — satu user boleh memiliki banyak role (spatie `HasRoles`).
2. **Gating via permission Shield** — bukan hardcode role; permission `*_user`
   digenerate Shield + UserPolicy manual.
3. **Role pemegang akses:** hanya `super_admin` yang diberi permission `*_user`
   (mengelola akun & role = sensitif).
4. **Fitur tambahan:** toggle **aktif/nonaktif** (kolom baru `is_active`) +
   atur status **verifikasi email** (`email_verified_at`). Avatar TIDAK
   diedit lewat form (hanya ditampilkan bila ada).

## Komponen

### 1. Migration `add_is_active_to_users`
- Tambah kolom `is_active` boolean, default `true`, setelah `avatar_path`.
- `User::$fillable` tambahkan `is_active`; cast `is_active => boolean`.

### 2. Model `User` — Activity Log & akses panel
- Tambahkan trait `Spatie\Activitylog\Traits\LogsActivity`.
- `getActivitylogOptions()`: `LogOptions::defaults()->logOnly(['name','email','is_active','email_verified_at'])->logOnlyDirty()`
  — **password & remember_token TIDAK boleh ter-log**.
- `canAccessPanel()` ditambah cek: `return $this->is_active;` (user nonaktif tak
  bisa login panel; logika existing "semua akun boleh akses" tetap, hanya
  difilter is_active).

### 3. `UserResource` (`app/Filament/Resources/UserResource.php`)
- `navigationGroup = 'Sistem'` (sejajar ActivityResource), label "User",
  icon user, `navigationSort` wajar.
- Pages: `ListUsers`, `CreateUser`, `EditUser`, `ViewUser`. Create/Edit extend
  `BaseCreateRecord`/`BaseEditRecord` (redirect + notifikasi sudah dihandle base).

**Form (Create/Edit):**
- Section "Identitas": `name` (required), `email` (email, unique ignore record),
  avatar dipajang read-only/placeholder bila ada (opsional, tidak editable).
- Section "Keamanan": `password` (TextInput::password, required saat create,
  optional saat edit → `dehydrated(fn ($state) => filled($state))`, `dehydrateStateUsing(fn ($s) => Hash::make($s))` atau andalkan cast `hashed` dengan tetap dehydrate hanya saat filled),
  `password_confirmation` (`confirmed`), helperText "kosongkan bila tidak diganti".
- Section "Akses & Status":
  - `roles` — `Select::make('roles')->multiple()->relationship('roles','name')->preload()->searchable()`, helperText daftar role.
  - `is_active` — Toggle, default true, helperText "user nonaktif tak bisa login".
  - `email_verified_at` — Toggle "Email terverifikasi" via `formatStateUsing`/`dehydrateStateUsing` (true → now(), false → null), atau DateTimePicker. Default: Toggle agar sederhana.

**Table:**
- Kolom: avatar (ImageColumn circular), `name`, `email`, `roles` (badge, separator),
  `is_active` (IconColumn boolean), `email_verified_at` (IconColumn boolean "terverifikasi"), `created_at` (toggleable).
- Action: `ViewAction` standalone + `ActionGroup` berisi `EditAction`, `DeleteAction`
  (konvensi action grouping).
- Filter: `roles` (SelectFilter relationship), `is_active` (TernaryFilter),
  verifikasi (TernaryFilter nullable email_verified_at).

**View (infolist):**
- Section "Identitas" (avatar, name, email), Section "Akses & Role"
  (roles badge), Section "Status" (is_active, email_verified_at, created_at/updated_at).
- Daftarkan `AuditTrailRelationManager` di `getRelations()`.

### 4. `UserPolicy` (`app/Policies/UserPolicy.php`)
- Manual policy meniru pola policy lain (mis. AgencyPolicy): method
  `viewAny/view/create/update/delete/...` cek `$user->can('view_any_user')` dst.
- Register di `AuthServiceProvider`/auto-discovery sesuai pola existing.

### 5. Shield permissions + Seeder
- Tambahkan `'user'` ke `RolePermissionSeeder::RESOURCES`.
- `shield:generate --all` akan menghasilkan permission `view_user`,
  `view_any_user`, `create_user`, `update_user`, dan (elevated) `delete_user`,
  `delete_any_user`, dst.
- Hanya `super_admin` yang punya `Permission::all()` → otomatis dapat `*_user`.
  `pengurus`/`petugas` TIDAK menerima permission user (tidak ditambah ke
  `permissionsFor()` mereka).

## Guardrails (anti self-lockout)

User tidak boleh mengunci dirinya sendiri:
- `DeleteAction` di table/view **disabled/hidden** bila record == `auth()->user()`.
- Saat update, jika record adalah dirinya sendiri: tidak boleh menonaktifkan
  (`is_active` dipaksa tetap true) dan tidak boleh mengosongkan seluruh role.
  Implementasi: validasi di form / mutate before save + `disabled()` pada
  toggle is_active untuk record diri sendiri.

## Notifikasi
- Semua notifikasi (create/edit/delete) wajib `->title()` + `->body()` — sudah
  dihandle base page classes + `Action::configureUsing()` global; action kustom
  (jika ada) sertakan body.

## Testing (Pest)
- Feature test UserResource: super_admin bisa render List/Create/Edit/View.
- petugas/pengurus **tidak** bisa akses (403 / menu hilden).
- Create user + assign multiple roles tersimpan.
- Edit tanpa isi password → password lama tetap.
- Guardrail: user tidak bisa menghapus / menonaktifkan dirinya sendiri.
- User dengan `is_active=false` tidak bisa `canAccessPanel`.
- Activity log tercatat saat create/update (tanpa bocor password).

## Di luar scope
- Manajemen Role/Permission detail (sudah ada di Livewire `app/Livewire/System/Roles`).
- Upload/ubah avatar lewat menu ini.
- Reset password via email flow.
