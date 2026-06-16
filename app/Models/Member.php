<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Member extends Model implements HasMedia
{
    use HasFactory;
    use HasUuids;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    /**
     * Allowed heir relationship values. Shared by the form Select and the
     * Excel import validation so the option set stays in one place.
     *
     * @var array<string, string>
     */
    public const HEIR_RELATIONSHIPS = [
        'Istri' => 'Istri',
        'Suami' => 'Suami',
        'Anak' => 'Anak',
        'Orang Tua' => 'Orang Tua',
        'Saudara Kandung' => 'Saudara Kandung',
        'Lainnya' => 'Lainnya',
    ];

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
        'mandatory_savings_amount',
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
        'mandatory_savings_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Auto-assign the member number on create when none was provided (3b/D2).
        static::creating(function (Member $member): void {
            if (blank($member->member_number)) {
                $member->member_number = static::generateMemberNumber();
            }
        });
    }

    /**
     * Generate the next member number in the format KM-YYYY-NNNN, reset per year.
     *
     * Race-safe: reads the current max for the year under a row lock inside a
     * transaction, so concurrent creates (interactive or bulk import) cannot
     * hand out a duplicate sequence. Soft-deleted rows are included so a number
     * is never reused.
     */
    public static function generateMemberNumber(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = sprintf('KM-%d-', $year);

        return DB::transaction(function () use ($prefix): string {
            $last = static::withTrashed()
                ->where('member_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('member_number')
                ->value('member_number');

            $next = $last ? ((int) substr($last, -4)) + 1 : 1;

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Record a document change (upload/delete) on this member's activity log.
     * Media lives in a separate table, so those changes don't trigger the
     * model's own LogsActivity — we log them explicitly here so they still
     * show up in the member's Audit Trail.
     */
    public function logDocumentActivity(string $description): void
    {
        activity()
            ->performedOn($this)
            ->causedBy(auth()->user())
            ->event('updated')
            ->log($description);
    }

    public function registerMediaCollections(): void
    {
        // Member documents (KTP, SK, application form, etc.). Restricted to
        // common document/image mime types (3d).
        $this->addMediaCollection('documents')
            ->acceptsMimeTypes([
                'application/pdf',
                'image/jpeg',
                'image/png',
            ]);
    }

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
