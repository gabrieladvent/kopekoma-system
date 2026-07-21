<?php

namespace App\Models;

use App\Casts\WholeRupiah;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MemberHolidaySaving extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'member_id',
        'period_year',
        'start_date',
        'end_date',
        'monthly_amount',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'monthly_amount' => WholeRupiah::class,
        'is_active' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(SavingsDeposit::class, 'member_id', 'member_id')
            ->where('savings_type', 'hari_raya')
            ->whereYear('period_month', $this->period_year)
            ->latest('deposit_date');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
