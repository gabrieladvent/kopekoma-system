<?php

namespace App\Livewire\Concerns;

use App\Models\Member;
use Illuminate\Support\Collection;

trait WithMemberPicker
{
    public ?string $member_id = null;

    public string $memberSearch = '';

    public ?string $selectedMemberLabel = null;

    public function hydrateSelectedMember(): void
    {
        if ($this->member_id !== null && $this->selectedMemberLabel === null) {
            $member = Member::find($this->member_id);

            $this->selectedMemberLabel = $member ? static::memberLabel($member) : null;
        }
    }

    public function selectMember(string $id): void
    {
        $member = Member::find($id);

        if ($member === null) {
            return;
        }

        $this->member_id = $member->id;
        $this->selectedMemberLabel = static::memberLabel($member);
        $this->memberSearch = '';

        $this->afterMemberSelected();
    }

    public function clearMember(): void
    {
        $this->member_id = null;
        $this->selectedMemberLabel = null;
        $this->memberSearch = '';

        $this->afterMemberSelected();
    }

    /**
     * Hasil pencarian anggota untuk dropdown picker (maks. 15).
     *
     * @return Collection<int, Member>
     */
    public function memberResults(): Collection
    {
        return Member::query()
            ->with(['agency:id,agency_name', 'grade:id,code'])
            ->when($this->memberSearch !== '', function ($query) {
                $term = '%'.$this->memberSearch.'%';
                $query->where(fn ($subQuery) => $subQuery->where('full_name', 'like', $term)
                    ->orWhere('member_number', 'like', $term)
                    ->orWhere('nik', 'like', $term)
                    ->orWhere('nip', 'like', $term));
            })
            ->orderBy('member_number')
            ->limit(15)
            ->get();
    }

    public static function memberLabel(Member $member): string
    {
        return "{$member->member_number} — {$member->full_name}";
    }

    /** Hook opsional: dipanggil setelah seleksi/clear anggota. */
    protected function afterMemberSelected(): void {}
}
