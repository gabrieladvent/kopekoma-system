<div x-data="{ tab: 'tampilan' }" class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight">Pengaturan</h2>
            <p class="mt-1 text-sm text-muted">Kelola tampilan, identitas aplikasi, email, dan parameter koperasi.</p>
        </div>
        <x-ui.button x-show="tab !== 'toko'" wire:click="save" wire:loading.attr="disabled" wire:target="save">
            <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                    stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            <span wire:loading.remove wire:target="save">Simpan Perubahan</span>
            <span wire:loading wire:target="save">Menyimpan…</span>
        </x-ui.button>
    </div>

    {{-- Tabs (signature underline) --}}
    <div class="flex gap-1 border-b border-border">
        @foreach (['tampilan' => 'Tampilan', 'aplikasi' => 'Aplikasi', 'email' => 'Email (SMTP)', 'koperasi' => 'Koperasi', 'toko' => 'Klien Toko'] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' ? 'border-primary text-primary' :
                    'border-transparent text-muted hover:text-text'"
                class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition duration-150 ease-out">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ===== TAMPILAN ===== --}}
    <div x-show="tab === 'tampilan'" x-transition.opacity.duration.200ms class="space-y-6" x-data="{
        get p() { return $wire.theme_primary || '#059669' },
        get s() { return $wire.theme_secondary || '#0d9488' },
    }"
        x-effect="document.documentElement.style.setProperty('--color-primary', p); document.documentElement.style.setProperty('--color-primary-hover', p); document.documentElement.style.setProperty('--color-secondary', s); document.documentElement.style.setProperty('--color-secondary-hover', s)">
        <x-ui.card title="Tema Warna"
            subtitle="Ubah warna brand. Preview langsung; klik Simpan untuk menerapkan ke seluruh aplikasi.">
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
                        @error('theme_primary')
                            <p class="text-xs text-danger">Format warna harus heksadesimal, mis. #059669.</p>
                        @enderror
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
                        @error('theme_secondary')
                            <p class="text-xs text-danger">Format warna harus heksadesimal, mis. #0d9488.</p>
                        @enderror
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

        {{-- Latar halaman login --}}
        @php
            $resolveLoginImg = function ($p) {
                if (\Illuminate\Support\Str::startsWith($p, ['http://', 'https://', '/'])) {
                    return $p;
                }
                if (\Illuminate\Support\Str::startsWith($p, 'images/')) {
                    return asset($p);
                }

                return \Illuminate\Support\Facades\Storage::url($p);
            };
            $loginCount = count($login_background_images);
            $loginMode = match (true) {
                $loginCount === 0 => ['Mode Teks', 'Panel kiri memakai gradient brand + teks (tanpa gambar).'],
                $loginCount === 1 => ['Ken Burns', 'Satu gambar dengan efek zoom pelan terus-menerus.'],
                default => ['Slideshow', $loginCount . ' gambar bergantian otomatis dengan transisi halus.'],
            };
        @endphp
        <x-ui.card title="Latar Halaman Login"
            subtitle="Atur panel kiri halaman login. Kosong = teks, 1 gambar = Ken Burns, 2+ = slideshow.">
            <x-slot:actions>
                @if ($loginCount > 0)
                    <x-ui.button variant="ghost" class="h-9 px-3" wire:click="clearLoginImages">Kosongkan</x-ui.button>
                @endif
            </x-slot:actions>

            {{-- Indikator mode aktif --}}
            <div class="flex items-center gap-3 rounded-xl border border-border bg-bg/60 p-4">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                    <x-ui.icon name="sparkles" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold">{{ $loginMode[0] }}</p>
                    <p class="text-xs text-muted">{{ $loginMode[1] }}</p>
                </div>
            </div>

            {{-- Daftar gambar tersimpan --}}
            @if ($loginCount > 0)
                <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($login_background_images as $i => $path)
                        <div wire:key="login-img-{{ $i }}-{{ \Illuminate\Support\Str::slug($path) }}"
                            class="group relative aspect-3/4 overflow-hidden rounded-xl border border-border bg-bg">
                            <img src="{{ $resolveLoginImg($path) }}" alt="Latar login {{ $i + 1 }}"
                                class="h-full w-full object-cover">
                            <span
                                class="absolute left-2 top-2 grid h-6 w-6 place-items-center rounded-full bg-black/55 text-xs font-semibold text-white backdrop-blur">{{ $i + 1 }}</span>
                            {{-- Kontrol --}}
                            <div
                                class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-1 bg-linear-to-t from-black/70 to-transparent p-2 opacity-0 transition group-hover:opacity-100">
                                <div class="flex gap-1">
                                    <button type="button" wire:click="moveLoginImage({{ $i }}, -1)"
                                        @disabled($i === 0)
                                        class="grid h-7 w-7 place-items-center rounded-md bg-white/90 text-text transition hover:bg-white disabled:opacity-40"
                                        aria-label="Naik">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15.75 19.5 8.25 12l7.5-7.5" />
                                        </svg>
                                    </button>
                                    <button type="button" wire:click="moveLoginImage({{ $i }}, 1)"
                                        @disabled($i === $loginCount - 1)
                                        class="grid h-7 w-7 place-items-center rounded-md bg-white/90 text-text transition hover:bg-white disabled:opacity-40"
                                        aria-label="Turun">
                                        <svg class="h-4 w-4 rotate-180" fill="none" stroke="currentColor"
                                            stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15.75 19.5 8.25 12l7.5-7.5" />
                                        </svg>
                                    </button>
                                </div>
                                <button type="button" wire:click="removeLoginImage({{ $i }})"
                                    class="grid h-7 w-7 place-items-center rounded-md bg-danger text-white transition hover:opacity-90"
                                    aria-label="Hapus">
                                    <x-ui.icon name="trash" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Tambah gambar --}}
            @if ($loginCount < \App\Livewire\Settings\ManageSettings::MAX_LOGIN_IMAGES)
                <div class="mt-5 space-y-1.5">
                    <label class="block text-sm font-medium text-text">Tambah Gambar</label>
                    <div class="flex items-center gap-3">
                        <input type="file" wire:model="loginImageUploads" multiple accept="image/*"
                            class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/20">
                        <svg wire:loading wire:target="loginImageUploads"
                            class="h-4 w-4 shrink-0 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                        </svg>
                    </div>
                    @if (count($loginImageUploads) > 0)
                        <p class="text-xs font-medium text-primary">{{ count($loginImageUploads) }} gambar siap
                            ditambahkan — klik “Simpan Perubahan”.</p>
                    @endif
                    <p class="text-xs text-muted">Potret (mis. 1200×1600) paling pas. JPG/PNG/SVG, maks 4 MB. Total
                        hingga {{ \App\Livewire\Settings\ManageSettings::MAX_LOGIN_IMAGES }} gambar.</p>
                    @error('loginImageUploads.*')
                        <p class="text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
            @else
                <p class="mt-5 text-xs text-muted">Batas maksimum
                    {{ \App\Livewire\Settings\ManageSettings::MAX_LOGIN_IMAGES }} gambar tercapai. Hapus salah satu
                    untuk menambah yang baru.</p>
            @endif
        </x-ui.card>
    </div>

    {{-- ===== APLIKASI ===== --}}
    <div x-show="tab === 'aplikasi'" x-transition.opacity.duration.200ms x-cloak>
        <x-ui.card title="Identitas Aplikasi" subtitle="Nama, logo, dan favicon.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Nama Aplikasi" wire:model="app_name" :error="$errors->first('app_name')" />
                <div></div>
                @php
                    $logoPreview =
                        $logoUpload && $logoUpload->isPreviewable()
                            ? $logoUpload->temporaryUrl()
                            : ($logo_path
                                ? \Illuminate\Support\Facades\Storage::url($logo_path)
                                : null);
                    $faviconPreview =
                        $faviconUpload && $faviconUpload->isPreviewable()
                            ? $faviconUpload->temporaryUrl()
                            : ($favicon_path
                                ? \Illuminate\Support\Facades\Storage::url($favicon_path)
                                : null);
                @endphp
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-text">Logo</label>
                    <div class="flex items-center gap-3">
                        <div
                            class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-xl border border-border bg-bg">
                            @if ($logoPreview)
                                <img src="{{ $logoPreview }}" alt="Pratinjau logo"
                                    class="h-full w-full object-contain p-1">
                            @else
                                <x-ui.icon name="home" class="h-5 w-5 text-muted" />
                            @endif
                            <div wire:loading wire:target="logoUpload"
                                class="absolute inset-0 grid place-items-center bg-surface/70">
                                <svg class="h-4 w-4 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                </svg>
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <input type="file" wire:model="logoUpload" accept="image/*"
                                class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/20">
                            <p class="mt-1 text-xs text-muted">PNG/SVG, maks 2 MB.</p>
                        </div>
                    </div>
                    @error('logoUpload')
                        <p class="text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-text">Favicon</label>
                    <div class="flex items-center gap-3">
                        <div
                            class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-xl border border-border bg-bg">
                            @if ($faviconPreview)
                                <img src="{{ $faviconPreview }}" alt="Pratinjau favicon"
                                    class="h-full w-full object-contain p-2">
                            @else
                                <x-ui.icon name="home" class="h-5 w-5 text-muted" />
                            @endif
                            <div wire:loading wire:target="faviconUpload"
                                class="absolute inset-0 grid place-items-center bg-surface/70">
                                <svg class="h-4 w-4 animate-spin text-primary" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                </svg>
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <input type="file" wire:model="faviconUpload" accept="image/*"
                                class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/20">
                            <p class="mt-1 text-xs text-muted">.png/.ico 32×32 atau .svg. Maks 1 MB.</p>
                        </div>
                    </div>
                    @error('faviconUpload')
                        <p class="text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- ===== EMAIL ===== --}}
    <div x-show="tab === 'email'" x-transition.opacity.duration.200ms x-cloak class="space-y-6">
        <x-ui.card title="Server SMTP" subtitle="Konfigurasi pengiriman email keluar.">
            <x-slot:actions>
                <x-ui.button variant="ghost" class="h-9 px-3" wire:click="sendTestEmail"
                    wire:loading.attr="disabled" wire:target="sendTestEmail">
                    <span wire:loading.remove wire:target="sendTestEmail">Kirim Email Tes</span>
                    <span wire:loading wire:target="sendTestEmail">Mengirim…</span>
                </x-ui.button>
            </x-slot:actions>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Host" wire:model="mail_host" placeholder="smtp.gmail.com" :error="$errors->first('mail_host')" />
                <x-ui.input label="Port" type="number" wire:model="mail_port" placeholder="587"
                    :error="$errors->first('mail_port')" />
                <div class="space-y-1">
                    <label class="block text-sm font-medium text-text">Enkripsi</label>
                    <select wire:model="mail_encryption"
                        class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        <option value="">Tanpa enkripsi</option>
                        <option value="tls">TLS (STARTTLS, port 587)</option>
                        <option value="ssl">SSL (port 465)</option>
                    </select>
                </div>
                <x-ui.input label="Username" wire:model="mail_username" autocomplete="off" :error="$errors->first('mail_username')" />
                <x-ui.input label="Password" type="password" wire:model="mail_password" autocomplete="new-password"
                    hint="Untuk Gmail gunakan App Password." :error="$errors->first('mail_password')" />
            </div>
        </x-ui.card>
        <x-ui.card title="Pengirim (From)">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Alamat Email Pengirim" type="email" wire:model="mail_from_address"
                    :error="$errors->first('mail_from_address')" />
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
                <x-ui.money-input label="Simpanan Pokok (sekali)" model="savings_pokok_amount"
                    :error="$errors->first('savings_pokok_amount')" />
                <x-ui.money-input label="Wajib Belanja / Bulan" model="savings_wajib_belanja_amount"
                    :error="$errors->first('savings_wajib_belanja_amount')" />
                <x-ui.money-input label="Minimal Setor Sukarela" model="savings_sukarela_min"
                    :error="$errors->first('savings_sukarela_min')" />
            </div>
        </x-ui.card>
        <x-ui.card title="Pinjaman" subtitle="Persentase ditulis desimal, mis. 0.01 = 1%.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Biaya Admin (× pokok)" type="number" step="0.00001"
                    wire:model="loan_admin_fee_rate" :error="$errors->first('loan_admin_fee_rate')" />
                <x-ui.input label="SWP (× pokok)" type="number" step="0.00001" wire:model="loan_swp_rate"
                    :error="$errors->first('loan_swp_rate')" />
                <x-ui.input label="Jasa (× pokok)" type="number" step="0.00001" wire:model="loan_interest_rate"
                    :error="$errors->first('loan_interest_rate')" />
                <x-ui.input label="Tabungan Berjangka (× pokok)" type="number" step="0.00001"
                    wire:model="loan_time_deposit_rate" :error="$errors->first('loan_time_deposit_rate')" />
                <x-ui.money-input label="Batas Pinjaman Jangka Pendek" model="loan_short_term_max"
                    :error="$errors->first('loan_short_term_max')" hint="Nominal maksimal pinjaman jangka pendek." />
            </div>
        </x-ui.card>
        <x-ui.card title="Identitas Koperasi (Kop Laporan)"
            subtitle="Tampil di kop & blok tanda tangan pada laporan PDF (setoran & angsuran).">
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.input label="Alamat Koperasi" wire:model="cooperative_address"
                        placeholder="Jl. Contoh No. 1, Kel. Dauh Puri, Kec. Denpasar Barat"
                        hint="Baris alamat di bawah nama koperasi pada kop." :error="$errors->first('cooperative_address')" />
                </div>
                <x-ui.input label="Kota" wire:model="cooperative_city" placeholder="Denpasar"
                    hint="Dipakai juga di baris tanggal blok tanda tangan (mis. “Denpasar, 08/07/2026”)."
                    :error="$errors->first('cooperative_city')" />

                {{-- Telepon dengan prefix +62 (visual). Simpan tanpa awalan; ditampilkan sebagai +62 di kop. --}}
                <div class="space-y-1">
                    <label class="block text-sm font-medium text-text">Telepon</label>
                    <div @class([
                        'flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary',
                        'border-border' => ! $errors->first('cooperative_phone'),
                        'border-danger focus-within:ring-danger' => $errors->first('cooperative_phone'),
                    ])>
                        <span class="pl-3 text-sm text-muted">+62</span>
                        <input type="tel" inputmode="tel" wire:model="cooperative_phone"
                            placeholder="812 3456 7890"
                            class="h-10 w-full rounded-lg bg-transparent px-2 text-sm text-text placeholder:text-muted focus-visible:outline-none">
                    </div>
                    @if ($errors->first('cooperative_phone'))
                        <p class="text-xs text-danger">{{ $errors->first('cooperative_phone') }}</p>
                    @else
                        <p class="text-xs text-muted">Tanpa awalan 0. Nomor tetap (mis. 361 234567) atau HP (mis. 812 3456 7890).</p>
                    @endif
                </div>

                <x-ui.input label="Nama Penandatangan" wire:model="signatory_name"
                    placeholder="mis. I Made Sudana"
                    hint="Nama yang tercetak di blok tanda tangan laporan." :error="$errors->first('signatory_name')" />
                <x-ui.input label="Jabatan Penandatangan" wire:model="signatory_position"
                    placeholder="mis. Ketua / Sekretaris / Bendahara"
                    hint="Tampil di atas nama penandatangan." :error="$errors->first('signatory_position')" />
            </div>
        </x-ui.card>
    </div>

    {{-- ===== KLIEN TOKO ===== --}}
    <div x-show="tab === 'toko'" x-transition.opacity.duration.200ms x-cloak>
        <livewire:settings.store-clients />
    </div>

    {{-- Toast (tengah atas) --}}
    <x-ui.toast-host />
</div>
