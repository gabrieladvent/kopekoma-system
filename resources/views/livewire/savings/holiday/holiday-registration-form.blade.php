<div class="mx-auto max-w-3xl space-y-6">
    {{-- Back --}}
    <a href="{{ $holidayId ? route('savings.holiday.show', $holidayId) : route('savings.holiday') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        {{ $holidayId ? 'Kembali ke detail' : 'Kembali ke daftar' }}
    </a>

    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-warning/10 text-warning">
            <x-ui.icon name="gift" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">
                {{ $holidayId ? 'Edit Pendaftaran Hari Raya' : 'Daftarkan Simpanan Hari Raya' }}
            </h2>
            <p class="mt-0.5 text-sm text-muted">
                Nominal bulanan yang disepakati anggota per tahun program — dipakai sebagai nominal terkunci saat setoran Hari Raya.
            </p>
        </div>
    </div>

    <form wire:submit="save">
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="user" class="h-5 w-5 text-warning" />
                <h3 class="text-sm font-semibold text-text">Registrasi</h3>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                {{-- Anggota --}}
                <div class="sm:col-span-2">
                    @include('livewire.savings.partials.member-picker')
                </div>

                {{-- Mulai --}}
                <div class="space-y-1">
                    <label for="start_date" class="block text-sm font-medium text-text">Mulai Pengumpulan</label>
                    <input id="start_date" type="date" wire:model.live="start_date"
                           @class([
                               'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                               'border-border' => ! $errors->has('start_date'),
                               'border-danger focus-visible:ring-danger' => $errors->has('start_date'),
                           ])>
                    @error('start_date')<p class="text-xs text-danger">{{ $message }}</p>
                    @else<p class="text-xs text-muted">Tanggal awal periode pengumpulan.</p>@enderror
                </div>

                {{-- Akhir --}}
                <div class="space-y-1">
                    <label for="end_date" class="block text-sm font-medium text-text">Akhir Pengumpulan</label>
                    <input id="end_date" type="date" wire:model.live="end_date"
                           @class([
                               'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                               'border-border' => ! $errors->has('end_date'),
                               'border-danger focus-visible:ring-danger' => $errors->has('end_date'),
                           ])>
                    @error('end_date')<p class="text-xs text-danger">{{ $message }}</p>
                    @else
                        <p class="text-xs text-muted">
                            Tahun program:
                            <span class="font-semibold text-warning">{{ $this->derivedYear() ?? '—' }}</span>
                            (diturunkan dari tahun tanggal akhir).
                        </p>
                    @enderror
                </div>

                {{-- Nominal bulanan --}}
                <div>
                    <x-ui.money-input label="Nominal Bulanan" model="monthly_amount" placeholder="100.000"
                        :error="$errors->first('monthly_amount')"
                        :hint="'Nominal per setoran untuk anggota ini di tahun tersebut.'" />
                </div>

                {{-- Aktif --}}
                <div class="space-y-1">
                    <span class="block text-sm font-medium text-text">Status</span>
                    <label class="flex h-10 cursor-pointer items-center gap-3 rounded-lg border border-border bg-surface px-3">
                        <button type="button" wire:click="$toggle('is_active')" role="switch" :aria-checked="@js($is_active)"
                                @class([
                                    'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition',
                                    'bg-primary' => $is_active,
                                    'bg-border' => ! $is_active,
                                ])>
                            <span @class(['inline-block h-3.5 w-3.5 transform rounded-full bg-white transition', 'translate-x-4.5' => $is_active, 'translate-x-1' => ! $is_active])></span>
                        </button>
                        <span class="text-sm text-text">{{ $is_active ? 'Aktif' : 'Non-Aktif' }}</span>
                    </label>
                    <p class="text-xs text-muted">Hanya registrasi aktif yang bisa dipakai saat setoran.</p>
                </div>

                {{-- Catatan --}}
                <div class="space-y-1 sm:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-text">Catatan <span class="text-muted">(opsional)</span></label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Catatan tambahan…"
                              @class([
                                  'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                  'border-border' => ! $errors->has('notes'),
                                  'border-danger focus-visible:ring-danger' => $errors->has('notes'),
                              ])></textarea>
                    @error('notes')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3 border-t border-border pt-5">
                <x-ui.button type="button" variant="ghost"
                    :href="$holidayId ? route('savings.holiday.show', $holidayId) : route('savings.holiday')" wire:navigate>
                    Batal
                </x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    {{ $holidayId ? 'Simpan Perubahan' : 'Daftarkan' }}
                </x-ui.button>
            </div>
        </x-ui.card>
    </form>

    <x-ui.toast-host />
</div>
