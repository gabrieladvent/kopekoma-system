import './bootstrap';

/*
 | Theme store (dark mode) — dipakai oleh tombol toggle di app shell.
 | Catatan: untuk cegah "flash", penentuan class .dark awal dilakukan oleh
 | inline script di <head> (lihat layouts/app). Di sini hanya store Alpine
 | untuk toggle + persist. Livewire 3 sudah menyertakan Alpine.
*/
document.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),

        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        },
    });
});
