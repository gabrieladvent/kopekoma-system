@php($isEdit = filled($memberId))
<div class="space-y-6"
     x-data
     @scroll-to-error.window="$nextTick(() => {
        const el = $el.querySelector('.border-danger, [aria-invalid=&quot;true&quot;]');
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            (el.querySelector('input, select, textarea') ?? el).focus?.({ preventScroll: true });
        }
     })">
    {{-- Back --}}
    <a href="{{ $isEdit ? route('master.members.show', $memberId) : route('master.members') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        {{ $isEdit ? 'Kembali ke detail' : 'Kembali ke daftar anggota' }}
    </a>

    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
            <x-ui.icon name="users" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">{{ $isEdit ? 'Edit Anggota' : 'Tambah Anggota' }}</h2>
            <p class="mt-0.5 text-sm text-muted">No. Anggota <span class="font-mono font-medium text-text">{{ $member_number }}</span> — digenerate otomatis, tidak dapat diubah.</p>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- ============ Identitas ============ --}}
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="identification" class="h-5 w-5 text-primary" />
                <h3 class="text-sm font-semibold text-text">Identitas Anggota</h3>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.input label="Nama Lengkap" name="full_name" wire:model="full_name" placeholder="Nama sesuai KTP" :error="$errors->first('full_name')" />
                </div>

                <div class="space-y-1">
                    <label for="nik" class="block text-sm font-medium text-text">NIK</label>
                    <input id="nik" type="text" inputmode="numeric" wire:model="nik" placeholder="16 digit" maxlength="16"
                           @class([
                               'h-10 w-full rounded-lg border bg-surface px-3 font-mono text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                               'border-border' => ! $errors->has('nik'),
                               'border-danger focus-visible:ring-danger' => $errors->has('nik'),
                           ])>
                    @error('nik')<p class="text-xs text-danger">{{ $message }}</p>@else<p class="text-xs text-muted">Nomor Induk Kependudukan, 16 digit, unik.</p>@enderror
                </div>

                <x-ui.input label="NIP" name="nip" wire:model="nip" placeholder="Wajib untuk ASN" :error="$errors->first('nip')"
                            :hint="$errors->has('nip') ? null : 'Wajib untuk ASN, opsional untuk Honorer.'" />

                <x-ui.input label="Tempat Lahir" name="birth_place" wire:model="birth_place" placeholder="Kota kelahiran" :error="$errors->first('birth_place')" />

                <x-ui.input label="Tanggal Lahir" name="birth_date" type="date" wire:model="birth_date" max="{{ now()->toDateString() }}" :error="$errors->first('birth_date')" />

                <div class="space-y-1">
                    <label for="gender" class="block text-sm font-medium text-text">Jenis Kelamin</label>
                    <select id="gender" wire:model="gender"
                            class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        <option value="L">Laki-laki</option>
                        <option value="P">Perempuan</option>
                    </select>
                    @error('gender')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-ui.card>

        {{-- ============ Instansi & Golongan ============ --}}
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="briefcase" class="h-5 w-5 text-primary" />
                <h3 class="text-sm font-semibold text-text">Instansi & Golongan</h3>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="agency_id" class="block text-sm font-medium text-text">OPD / Instansi</label>
                    <select id="agency_id" wire:model="agency_id"
                            @class([
                                'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                'border-border' => ! $errors->has('agency_id'),
                                'border-danger focus-visible:ring-danger' => $errors->has('agency_id'),
                            ])>
                        <option value="">— Pilih OPD —</option>
                        @foreach ($agencyOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('agency_id')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="space-y-1">
                    <label for="grade_id" class="block text-sm font-medium text-text">Golongan</label>
                    <select id="grade_id" wire:model.live="grade_id"
                            @class([
                                'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                'border-border' => ! $errors->has('grade_id'),
                                'border-danger focus-visible:ring-danger' => $errors->has('grade_id'),
                            ])>
                        <option value="">— Pilih golongan —</option>
                        @foreach ($gradeOptions as $g)
                            <option value="{{ $g->id }}">{{ $g->code }} — {{ $g->name }}</option>
                        @endforeach
                    </select>
                    @error('grade_id')<p class="text-xs text-danger">{{ $message }}</p>@else<p class="text-xs text-muted">Menentukan default nominal simpanan wajib (snapshot).</p>@enderror
                </div>

                <div class="space-y-1">
                    <label for="employment_status" class="block text-sm font-medium text-text">Status Kepegawaian</label>
                    <select id="employment_status" wire:model.live="employment_status"
                            class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        <option value="ASN">ASN</option>
                        <option value="Honorer">Honorer</option>
                    </select>
                    @error('employment_status')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                <x-ui.input label="Jabatan" name="position" wire:model="position" placeholder="Opsional" :error="$errors->first('position')" />
            </div>
        </x-ui.card>

        {{-- ============ Keuangan ============ --}}
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="cash" class="h-5 w-5 text-primary" />
                <h3 class="text-sm font-semibold text-text">Keuangan</h3>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Simpanan wajib (format ribuan via Alpine, simpan integer) --}}
                <div class="space-y-1"
                     x-data="{
                        raw: @entangle('mandatory_savings_amount'),
                        display: '',
                        fmt(v) { v = String(v ?? '').replace(/\D/g, ''); return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
                        init() {
                            this.display = this.fmt(this.raw);
                            this.$watch('raw', (v) => { const f = this.fmt(v); if (f !== this.display) this.display = f; });
                        },
                        onInput(e) {
                            const digits = e.target.value.replace(/\D/g, '');
                            this.raw = digits === '' ? null : parseInt(digits, 10);
                            this.display = this.fmt(digits);
                        },
                     }">
                    <label for="msa" class="block text-sm font-medium text-text">Simpanan Wajib / Bulan</label>
                    <div @class([
                            'flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary',
                            'border-border' => ! $errors->has('mandatory_savings_amount'),
                            'border-danger focus-within:ring-danger' => $errors->has('mandatory_savings_amount'),
                            'opacity-60' => ! $canOverride,
                         ])>
                        <span class="pl-3 text-sm text-muted">Rp</span>
                        <input id="msa" type="text" inputmode="numeric" :value="display" @input="onInput($event)" placeholder="50.000"
                               @disabled(! $canOverride)
                               class="h-10 w-full rounded-lg bg-transparent px-2 text-sm text-text placeholder:text-muted focus-visible:outline-none disabled:cursor-not-allowed">
                    </div>
                    @error('mandatory_savings_amount')<p class="text-xs text-danger">{{ $message }}</p>
                    @else<p class="text-xs text-muted">{{ $canOverride ? 'Default dari golongan; boleh di-override.' : 'Default dari golongan. Hanya Pengurus ke atas yang dapat mengubah.' }}</p>@enderror
                </div>

                <div class="hidden sm:block"></div>

                <x-ui.input label="No. Rekening Gaji" name="payroll_account_number" wire:model="payroll_account_number" placeholder="Rekening tujuan potong gaji" :error="$errors->first('payroll_account_number')" />

                <x-ui.input label="Nama Bank" name="bank_name" wire:model="bank_name" placeholder="Opsional" :error="$errors->first('bank_name')" />
            </div>
        </x-ui.card>

        {{-- ============ Kontak & Alamat ============ --}}
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="phone" class="h-5 w-5 text-primary" />
                <h3 class="text-sm font-semibold text-text">Kontak & Alamat</h3>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="phone_number" class="block text-sm font-medium text-text">No. HP</label>
                    <div class="flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary"
                         @class(['border-border' => ! $errors->has('phone_number'), 'border-danger focus-within:ring-danger' => $errors->has('phone_number')])>
                        <span class="pl-3 text-sm text-muted">+62</span>
                        <input id="phone_number" type="tel" inputmode="numeric" wire:model="phone_number" placeholder="81234567890"
                               class="h-10 w-full rounded-lg bg-transparent px-2 text-sm text-text placeholder:text-muted focus-visible:outline-none">
                    </div>
                    @error('phone_number')<p class="text-xs text-danger">{{ $message }}</p>@else<p class="text-xs text-muted">Tanpa angka 0 di depan. Disimpan dengan awalan +62.</p>@enderror
                </div>

                <div class="hidden sm:block"></div>

                <div class="space-y-1 sm:col-span-2">
                    <label for="address" class="block text-sm font-medium text-text">Alamat</label>
                    <textarea id="address" wire:model="address" rows="2" placeholder="Jl. ... No. ..."
                              @class([
                                  'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                  'border-border' => ! $errors->has('address'),
                                  'border-danger focus-visible:ring-danger' => $errors->has('address'),
                              ])></textarea>
                    @error('address')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-ui.card>

        {{-- ============ Keanggotaan ============ --}}
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="calendar" class="h-5 w-5 text-primary" />
                <h3 class="text-sm font-semibold text-text">Keanggotaan</h3>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.input label="Tanggal Bergabung" name="join_date" type="date" wire:model="join_date" :error="$errors->first('join_date')" />

                <div class="space-y-1">
                    <label for="statusForm" class="block text-sm font-medium text-text">Status</label>
                    <select id="statusForm" wire:model.live="statusForm"
                            class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        <option value="Aktif">Aktif</option>
                        <option value="Non-Aktif">Non-Aktif</option>
                        <option value="Keluar">Keluar</option>
                        <option value="Meninggal">Meninggal</option>
                    </select>
                    @error('statusForm')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                @if (in_array($statusForm, ['Keluar', 'Meninggal'], true))
                    <x-ui.input label="Tanggal Keluar" name="exit_date" type="date" wire:model="exit_date" :error="$errors->first('exit_date')"
                                :hint="$errors->has('exit_date') ? null : 'Wajib bila status Keluar / Meninggal.'" />
                @else
                    <div class="hidden sm:block"></div>
                @endif
            </div>
        </x-ui.card>

        {{-- ============ Ahli Waris ============ --}}
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="heart" class="h-5 w-5 text-primary" />
                <h3 class="text-sm font-semibold text-text">Ahli Waris</h3>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.input label="Nama Ahli Waris" name="heir_name" wire:model="heir_name" placeholder="Nama lengkap" :error="$errors->first('heir_name')" />

                <div class="space-y-1">
                    <label for="heir_relationship" class="block text-sm font-medium text-text">Hubungan</label>
                    <select id="heir_relationship" wire:model="heir_relationship"
                            @class([
                                'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                'border-border' => ! $errors->has('heir_relationship'),
                                'border-danger focus-visible:ring-danger' => $errors->has('heir_relationship'),
                            ])>
                        <option value="">— Pilih hubungan —</option>
                        @foreach ($heirRelationships as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('heir_relationship')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="space-y-1">
                    <label for="heir_phone_number" class="block text-sm font-medium text-text">No. HP Ahli Waris</label>
                    <div class="flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary"
                         @class(['border-border' => ! $errors->has('heir_phone_number'), 'border-danger focus-within:ring-danger' => $errors->has('heir_phone_number')])>
                        <span class="pl-3 text-sm text-muted">+62</span>
                        <input id="heir_phone_number" type="tel" inputmode="numeric" wire:model="heir_phone_number" placeholder="81234567890"
                               class="h-10 w-full rounded-lg bg-transparent px-2 text-sm text-text placeholder:text-muted focus-visible:outline-none">
                    </div>
                    @error('heir_phone_number')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-ui.card>

        {{-- ============ Dokumen (opsional) ============ --}}
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="paper-clip" class="h-5 w-5 text-primary" />
                <h3 class="text-sm font-semibold text-text">Dokumen <span class="font-normal text-muted">(opsional)</span></h3>
            </div>

            <div class="mt-4 space-y-4">
                {{-- Dokumen tersimpan (hanya saat edit) --}}
                @if ($isEdit && $documents->isNotEmpty())
                    <div class="divide-y divide-border rounded-xl border border-border">
                        @foreach ($documents as $doc)
                            <div class="flex items-center gap-3 px-3 py-2.5" wire:key="exist-{{ $doc->id }}">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-bg text-muted">
                                    <x-ui.icon name="document" class="h-5 w-5" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-text">{{ $doc->file_name }}</p>
                                    <p class="text-xs text-muted">{{ $doc->human_readable_size }} · {{ $doc->created_at?->translatedFormat('d M Y H:i') }}</p>
                                </div>
                                <a href="{{ $doc->getFullUrl() }}" target="_blank" rel="noopener" title="Lihat"
                                   class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text">
                                    <x-ui.icon name="eye" class="h-4.5 w-4.5" />
                                </a>
                                <button type="button" title="Hapus"
                                        x-on:click="$dispatch('confirm-action', {
                                            title: 'Hapus dokumen ini?',
                                            message: '{{ addslashes($doc->file_name) }} akan dihapus permanen.',
                                            confirmLabel: 'Hapus', variant: 'danger',
                                            method: 'deleteDocument', params: [{{ $doc->id }}],
                                        })"
                                        class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-danger/10 hover:text-danger">
                                    <x-ui.icon name="trash" class="h-4.5 w-4.5" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Tambah berkas baru — bisa pilih beberapa sekaligus atau menambah
                     bertahap; tiap pilihan digabung ke antrean di bawah. --}}
                <div class="space-y-2">
                    <input type="file" wire:model="newUploads" multiple accept="application/pdf,image/jpeg,image/png"
                           class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/20">
                    <p class="text-xs text-muted">KTP, SK, formulir, dll. PDF/JPG/PNG, maks 5 MB per berkas. Bisa pilih beberapa berkas sekaligus atau menambah bertahap. Dilampirkan saat form disimpan.</p>
                    @error('newUploads.*')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    @error('uploads.*')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    @error('uploads')<p class="text-xs text-danger">{{ $message }}</p>@enderror

                    <div class="flex items-center gap-2" wire:loading wire:target="newUploads">
                        <svg class="h-4 w-4 animate-spin text-muted" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span class="text-xs text-muted">Mengunggah…</span>
                    </div>
                </div>

                {{-- Antrean berkas siap diunggah --}}
                @if (count($uploads))
                    <div class="space-y-1">
                        <p class="text-xs font-medium text-muted">{{ count($uploads) }} berkas siap dilampirkan</p>
                        <div class="divide-y divide-border rounded-xl border border-dashed border-border">
                            @foreach ($uploads as $i => $file)
                                <div class="flex items-center gap-3 px-3 py-2" wire:key="pending-{{ $i }}">
                                    <x-ui.icon name="document" class="h-4.5 w-4.5 shrink-0 text-muted" />
                                    <span class="min-w-0 flex-1 truncate text-sm text-text">{{ $file->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="removeUpload({{ $i }})" title="Buang"
                                            class="grid h-7 w-7 place-items-center rounded-lg text-muted transition hover:bg-danger/10 hover:text-danger">
                                        <x-ui.icon name="x" class="h-4 w-4" />
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- Actions --}}
        <div class="flex items-center justify-end gap-3">
            <x-ui.button variant="ghost" :href="$isEdit ? route('master.members.show', $memberId) : route('master.members')" wire:navigate>Batal</x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Anggota' }}
            </x-ui.button>
        </div>
    </form>

    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
