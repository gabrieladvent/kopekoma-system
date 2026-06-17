<?php

namespace App\Actions;

use App\Contracts\Reversible;
use App\Exceptions\CannotReverseTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReverseTransaction
{
    private const INACTIVE_STATUSES = ['Keluar', 'Meninggal'];

    /**
     * @template T of Model&Reversible
     *
     * @param  T  $original
     * @param  int|null  $causerId  user id pelaku (default: auth user)
     * @return T baris reversal yang dibuat
     */
    public function __invoke(Model&Reversible $original, string $reason, ?int $causerId = null): Model
    {
        $causerId ??= auth()->id();

        if ($original->is_reversal === true) {
            throw CannotReverseTransaction::isAReversal();
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw CannotReverseTransaction::reasonRequired();
        }

        if (in_array($original->member?->status, self::INACTIVE_STATUSES, true)) {
            throw CannotReverseTransaction::memberInactive();
        }

        return DB::transaction(function () use ($original, $reason, $causerId): Model {
            /** @var Model&Reversible $locked */
            $locked = $original->newQuery()->lockForUpdate()->findOrFail($original->getKey());

            if ($locked->is_reversal === true) {
                throw CannotReverseTransaction::isAReversal();
            }

            $attributes = [
                ...$locked->reverseClone(),
                'is_reversal' => true,
                'reversal_of_id' => $locked->getKey(),
                'notes' => $reason,
                'recorded_by' => $causerId,
            ];

            try {
                $reversal = $locked->newInstance()->forceFill($attributes);

                $reversal->save();
            } catch (UniqueConstraintViolationException $e) {
                throw CannotReverseTransaction::alreadyReversed();
            }

            activity()
                ->performedOn($reversal)
                ->causedBy($causerId)
                ->event('reversal')
                ->withProperties([
                    'reversal_of_id' => $locked->getKey(),
                    'amount' => $locked->amount,
                ])
                ->log("Reversal: {$reason}");

            return $reversal;
        });
    }
}
