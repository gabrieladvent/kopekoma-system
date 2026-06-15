<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Agency extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'agency_code',
        'agency_name',
        'address',
        'payroll_treasurer',
        'pic_phone_number',
        'status',
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
