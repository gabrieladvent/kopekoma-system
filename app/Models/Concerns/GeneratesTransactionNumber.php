<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait GeneratesTransactionNumber
{
    abstract public function transactionNumberColumn(): string;

    abstract public function transactionNumberPrefix(): string;

    protected static function bootGeneratesTransactionNumber(): void
    {
        static::creating(function (Model $model): void {
            $column = $model->transactionNumberColumn();

            if (blank($model->{$column})) {
                $model->{$column} = $model->generateTransactionNumber();
            }
        });
    }

    public function generateTransactionNumber(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        $column = $this->transactionNumberColumn();

        $prefix = sprintf('%s-%d-', $this->transactionNumberPrefix(), $year);

        return DB::transaction(function () use ($column, $prefix): string {
            $last = static::query()
                ->where($column, 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc($column)
                ->value($column);

            $next = $last ? ((int) substr($last, -6)) + 1 : 1;

            return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }
}
