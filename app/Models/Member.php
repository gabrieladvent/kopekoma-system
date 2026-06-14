<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Member extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $fillable = [
        'member_number',
        'full_name',
        'birth_place',
        'birth_date',
        'gender',
        'nik',
        'nip',
        'agency_id',
        'position',
        'grade_id',
        'employment_status',
        'payroll_account_number',
        'bank_name',
        'address',
        'phone_number',
        'join_date',
        'exit_date',
        'heir_name',
        'heir_relationship',
        'heir_phone_number',
        'status',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'join_date' => 'date',
        'exit_date' => 'date',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function holidaySavings(): HasMany
    {
        return $this->hasMany(MemberHolidaySaving::class);
    }

    public function savingsDeposits(): HasMany
    {
        return $this->hasMany(SavingsDeposit::class);
    }

    public function savingsWithdrawals(): HasMany
    {
        return $this->hasMany(SavingsWithdrawal::class);
    }

    public function shoppingTransactions(): HasMany
    {
        return $this->hasMany(ShoppingTransaction::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function blacklists(): HasMany
    {
        return $this->hasMany(LoanBlacklist::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
