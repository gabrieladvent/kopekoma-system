<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StoreClient extends Model implements HasApiTokensContract
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;
    use LogsActivity;

    protected $fillable = [
        'name',
        'client_id',
        'client_secret',
        'is_active',
        'can_refund',
    ];

    /**
     * `client_secret` jangan pernah terekspos lewat serialisasi/array.
     *
     * @var list<string>
     */
    protected $hidden = [
        'client_secret',
    ];

    protected $casts = [
        'client_secret' => 'hashed',
        'is_active' => 'boolean',
        'can_refund' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'client_id', 'is_active'])
            ->logOnlyDirty();
    }
}
