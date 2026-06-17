<?php

namespace App\Contracts;

interface Reversible
{
    /**
     * Field yang di-copy ke baris reversal. Action menambahkan
     * is_reversal/reversal_of_id/notes/recorded_by di atas ini.
     *
     * @return array<string, mixed>
     */
    public function reverseClone(): array;
}
