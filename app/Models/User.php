<?php

namespace App\Models;

use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, MustVerifyEmailTrait, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'avatar_path',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Active accounts may access the admin panel; per-resource access is
     * enforced by Filament Shield policies. Deactivated users are locked out.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /**
     * Activity log: track meaningful changes only. Password and tokens are
     * never logged.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_active', 'email_verified_at'])
            ->logOnlyDirty();
    }

    /**
     * Kirim email verifikasi memakai notifikasi berbranding koperasi
     * (menggantikan template bawaan Laravel).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    /**
     * Hapus seluruh sesi login milik user ini dari penyimpanan sesi database,
     * dan cabut remember-token sehingga cookie "ingat saya" di perangkat lama
     * ikut mati. Efeknya: user langsung ter-logout dari semua perangkat pada
     * request berikutnya. Dipakai saat akun dinonaktifkan / password direset.
     *
     * @param  string|null  $exceptSessionId  sesi yang dipertahankan (mis. sesi
     *                                        perangkat yang baru saja login) — untuk penegakan satu-perangkat.
     */
    public function invalidateSessions(?string $exceptSessionId = null): void
    {
        // Cabut remember-token lama agar cookie recaller perangkat lain invalid.
        if ($exceptSessionId === null) {
            $this->forceFill(['remember_token' => Str::random(60)])->save();
        }

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /**
     * Public URL of the stored profile photo, or null when none is set.
     */
    public function avatarUrl(): ?string
    {
        return blank($this->avatar_path)
            ? null
            : Storage::disk('public')->url($this->avatar_path);
    }

    /**
     * Avatar shown inside the Filament panel.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatarUrl();
    }
}
