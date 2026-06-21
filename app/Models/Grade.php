<?php

namespace App\Models;

use App\Casts\WholeRupiah;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Grade extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code',
        'name',
        'mandatory_savings_amount',
        'is_active',
    ];

    protected $casts = [
        'mandatory_savings_amount' => WholeRupiah::class,
        'is_active' => 'boolean',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
