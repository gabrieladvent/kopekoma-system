@props([
    'label' => null,
    'model' => null,        // nama properti Livewire (wajib) — di-entangle
    'error' => null,
    'hint' => null,
    'placeholder' => '0',
    'id' => null,
    'disabled' => false,
])

{{--
    Input rupiah: tampil dengan pemisah ribuan (Alpine), simpan integer murni
    ke properti Livewire. Sepadan dengan MoneyInput Filament (stripCharacters '.').
    Pakai: <x-ui.money-input label="Nominal" model="amount" :error="$errors->first('amount')" />
--}}
@php($field = $id ?? $model)

<div class="space-y-1"
     x-data="{
        raw: @entangle($model),
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
    @if ($label)
        <label for="{{ $field }}" class="block text-sm font-medium text-text">{{ $label }}</label>
    @endif

    <div @class([
            'flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary',
            'border-border' => ! $error,
            'border-danger focus-within:ring-danger' => $error,
            'opacity-60' => $disabled,
         ])>
        <span class="pl-3 text-sm text-muted">Rp</span>
        <input id="{{ $field }}" type="text" inputmode="numeric" :value="display" @input="onInput($event)"
               placeholder="{{ $placeholder }}" @disabled($disabled)
               {{ $attributes->class('h-10 w-full rounded-lg bg-transparent px-2 text-sm text-text placeholder:text-muted focus-visible:outline-none disabled:cursor-not-allowed') }}>
    </div>

    @if ($error)
        <p class="text-xs text-danger">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-muted">{{ $hint }}</p>
    @endif
</div>
