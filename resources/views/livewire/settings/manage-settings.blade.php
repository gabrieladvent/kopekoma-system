<div
    x-data="{
        tab: 'tampilan',
        toasts: [],
        pushToast(e) {
            const id = Date.now();
            this.toasts.push({ id, type: e.detail.type || 'success', message: e.detail.message });
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 4000);
        },
    }"
    @toast.window="pushToast($event)"
    class="space-y-6"
>
    {{-- Header --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight">Pengaturan</h2>
            <p class="mt-1 text-sm text-muted">Kelola tampilan, identitas aplikasi, email, dan parameter koperasi.</p>
        </div>
        <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
            <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            <span wire:loading.remove wire:target="save">Simpan Perubahan</span>
            <span wire:loading wire:target="save">Menyimpan…</span>
        </x-ui.button>
    </div>

    {{-- Tabs (signature underline) --}}
    <div class="flex gap-1 border-b border-border">
        @foreach (['tampilan' => 'Tampilan', 'aplikasi' => 'Aplikasi', 'email' => 'Email (SMTP)', 'koperasi' => 'Koperasi'] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-text'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition duration-150 ease-out">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ===== TAMPILAN ===== --}}
    <div x-show="tab === 'tampilan'" x-transition.opacity.duration.200ms
         x-data="{
            get p() { return $wire.theme_primary || '#059669' },
            get s() { return $wire.theme_secondary || '#0d9488' },
         }"
         x-effect="document.documentElement.style.setProperty('--color-primary', p); document.documentElement.style.setProperty('--color-primary-hover', p); document.documentElement.style.setProperty('--color-secondary', s); document.documentElement.style.setProperty('--color-secondary-hover', s)">
        <x-ui.card title="Tema Warna" subtitle="Ubah warna brand. Preview langsung; klik Simpan untuk menerapkan ke seluruh aplikasi.">
            <div class="grid gap-8 lg:grid-cols-2">
                <div class="space-y-5">
                    {{-- Primary --}}
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-text">Warna Primary</label>
                        <div class="flex items-center gap-3">
                            <input type="color" wire:model.live="theme_primary"
                                   class="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-border bg-surface p-1">
                            <input type="text" wire:model.live.debounce.300ms="theme_primary" placeholder="#059669"
                                   class="h-10 w-36 rounded-lg border border-border bg-surface px-3 font-mono text-sm uppercase text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        </div>
                        @error('theme_primary') <p class="text-xs text-danger">Format warna harus heksadesimal, mis. #059669.</p> @enderror
                    </div>
                    {{-- Secondary --}}
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-text">Warna Secondary</label>
                        <div class="flex items-center gap-3">
                            <input type="color" wire:model.live="theme_secondary"
                                   class="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-border bg-surface p-1">
                            <input type="text" wire:model.live.debounce.300ms="theme_secondary" placeholder="#0d9488"
                                   class="h-10 w-36 rounded-lg border border-border bg-surface px-3 font-mono text-sm uppercase text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        </div>
                        @error('theme_secondary') <p class="text-xs text-danger">Format warna harus heksadesimal, mis. #0d9488.</p> @enderror
                    </div>
                    <button type="button" wire:click="resetTheme"
                            class="text-sm font-medium text-muted underline-offset-2 hover:text-text hover:underline">
                        Kembalikan ke default (emerald/teal)
                    </button>
                </div>

                {{-- Live preview --}}
                <div class="rounded-xl border border-border p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted">Pratinjau</p>
                    <div class="bg-brand-gradient mt-3 rounded-xl p-5 text-white">
                        <p class="text-sm text-white/80">Total Simpanan</p>
                        <p class="text-2xl font-bold tabular-nums">Rp 1,25 M</p>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-ui.button class="pointer-events-none">Primary</x-ui.button>
                        <x-ui.button variant="secondary" class="pointer-events-none">Secondary</x-ui.button>
                        <x-ui.badge color="primary">Aktif</x-ui.badge>
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- ===== APLIKASI ===== --}}
    <div x-show="tab === 'aplikasi'" x-transition.opacity.duration.200ms x-cloak>
        <x-ui.card title="Identitas Aplikasi" subtitle="Nama, logo, dan favicon.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Nama Aplikasi" wire:model="app_name" :error="$errors->first('app_name')" />
                <div></div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-text">Logo</label>
                    @if ($logo_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($logo_path) }}" alt="Logo" class="mb-2 h-12 rounded-lg border border-border bg-surface object-contain p-1">
                    @endif
                    <input type="file" wire:model="logoUpload" accept="image/*"
                           class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/20">
                    <p class="text-xs text-muted">PNG/SVG, maks 2 MB.</p>
                    @error('logoUpload') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                    <div wire:loading wire:target="logoUpload" class="text-xs text-muted">Mengunggah…</div>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-text">Favicon</label>
                    @if ($favicon_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($favicon_path) }}" alt="Favicon" class="mb-2 h-10 w-10 rounded-lg border border-border bg-surface object-contain p-1">
                    @endif
                    <input type="file" wire:model="faviconUpload" accept="image/*"
                           class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/20">
                    <p class="text-xs text-muted">.png/.ico 32×32 atau .svg. Maks 1 MB.</p>
                    @error('faviconUpload') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                    <div wire:loading wire:target="faviconUpload" class="text-xs text-muted">Mengunggah…</div>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- ===== EMAIL ===== --}}
    <div x-show="tab === 'email'" x-transition.opacity.duration.200ms x-cloak class="space-y-6">
        <x-ui.card title="Server SMTP" subtitle="Konfigurasi pengiriman email keluar.">
            <x-slot:actions>
                <x-ui.button variant="ghost" class="h-9 px-3" wire:click="sendTestEmail" wire:loading.attr="disabled" wire:target="sendTestEmail">
                    <span wire:loading.remove wire:target="sendTestEmail">Kirim Email Tes</span>
                    <span wire:loading wire:target="sendTestEmail">Mengirim…</span>
                </x-ui.button>
            </x-slot:actions>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Host" wire:model="mail_host" placeholder="smtp.gmail.com" :error="$errors->first('mail_host')" />
                <x-ui.input label="Port" type="number" wire:model="mail_port" placeholder="587" :error="$errors->first('mail_port')" />
                <div class="space-y-1">
                    <label class="block text-sm font-medium text-text">Enkripsi</label>
                    <select wire:model="mail_encryption" class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        <option value="">Tanpa enkripsi</option>
                        <option value="tls">TLS (STARTTLS, port 587)</option>
                        <option value="ssl">SSL (port 465)</option>
                    </select>
                </div>
                <x-ui.input label="Username" wire:model="mail_username" autocomplete="off" :error="$errors->first('mail_username')" />
                <x-ui.input label="Password" type="password" wire:model="mail_password" autocomplete="new-password" hint="Untuk Gmail gunakan App Password." :error="$errors->first('mail_password')" />
            </div>
        </x-ui.card>
        <x-ui.card title="Pengirim (From)">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Alamat Email Pengirim" type="email" wire:model="mail_from_address" :error="$errors->first('mail_from_address')" />
                <x-ui.input label="Nama Pengirim" wire:model="mail_from_name" :error="$errors->first('mail_from_name')" />
            </div>
            <div class="mt-5 border-t border-border pt-5">
                <x-ui.input label="Kirim email tes ke" type="email" wire:model="testRecipient" :error="$errors->first('testRecipient')" />
            </div>
        </x-ui.card>
    </div>

    {{-- ===== KOPERASI ===== --}}
    <div x-show="tab === 'koperasi'" x-transition.opacity.duration.200ms x-cloak class="space-y-6">
        <x-ui.card title="Simpanan">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input label="Simpanan Pokok (sekali)" type="number" wire:model="savings_pokok_amount" :error="$errors->first('savings_pokok_amount')" />
                <x-ui.input label="Wajib Belanja / Bulan" type="number" wire:model="savings_wajib_belanja_amount" :error="$errors->first('savings_wajib_belanja_amount')" />
                <x-ui.input label="Minimal Setor Sukarela" type="number" wire:model="savings_sukarela_min" :error="$errors->first('savings_sukarela_min')" />
            </div>
        </x-ui.card>
        <x-ui.card title="Pinjaman" subtitle="Persentase ditulis desimal, mis. 0.01 = 1%.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Biaya Admin (× pokok)" type="number" step="0.00001" wire:model="loan_admin_fee_rate" :error="$errors->first('loan_admin_fee_rate')" />
                <x-ui.input label="SWP (× pokok)" type="number" step="0.00001" wire:model="loan_swp_rate" :error="$errors->first('loan_swp_rate')" />
                <x-ui.input label="Jasa (× pokok)" type="number" step="0.00001" wire:model="loan_interest_rate" :error="$errors->first('loan_interest_rate')" />
                <x-ui.input label="Tabungan Berjangka (× pokok)" type="number" step="0.00001" wire:model="loan_time_deposit_rate" :error="$errors->first('loan_time_deposit_rate')" />
                <x-ui.input label="Batas Pinjaman Jangka Pendek" type="number" wire:model="loan_short_term_max" :error="$errors->first('loan_short_term_max')" />
            </div>
        </x-ui.card>
    </div>

    {{-- Toasts --}}
    <div class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-full max-w-sm flex-col gap-2">
        <template x-for="t in toasts" :key="t.id">
            <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                 class="pointer-events-auto flex items-start gap-3 rounded-xl border border-border bg-surface p-4 shadow-lg"
                 :class="t.type === 'danger' ? 'border-danger/30' : 'border-success/30'">
                <span class="mt-0.5 h-2 w-2 shrink-0 rounded-full" :class="t.type === 'danger' ? 'bg-danger' : 'bg-success'"></span>
                <p class="text-sm text-text" x-text="t.message"></p>
            </div>
        </template>
    </div>
</div>
