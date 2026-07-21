import './bootstrap';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

/*
 | Theme store (dark mode) — dipakai oleh tombol toggle di app shell.
 | Catatan: untuk cegah "flash", penentuan class .dark awal dilakukan oleh
 | inline script di <head> (lihat layouts/app). Di sini hanya store Alpine
 | untuk toggle + persist. Livewire 3 sudah menyertakan Alpine.
*/
document.addEventListener('alpine:init', () => {
    /*
     | Avatar cropper — buka modal saat pilih file, crop 1:1 (mask lingkaran),
     | zoom + rotate, lalu unggah hasil crop ke properti Livewire `photo`
     | via $wire.upload(). Setelah itu alur "Simpan Foto" yang ada dipakai
     | untuk menyimpan permanen.
    */
    window.Alpine.data('avatarCropper', () => ({
        open: false,
        loading: false,
        imageSrc: null,
        cropper: null,

        pickFile(event) {
            const file = event.target.files[0];
            event.target.value = ''; // izinkan pilih file sama lagi
            if (! file || ! file.type.startsWith('image/')) return;

            const reader = new FileReader();
            reader.onload = (e) => {
                this.imageSrc = e.target.result;
                this.open = true;
                this.$nextTick(() => this.initCropper());
            };
            reader.readAsDataURL(file);
        },

        initCropper() {
            this.destroyCropper();
            this.cropper = new Cropper(this.$refs.image, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                background: false,
                guides: false,
                center: false,
                toggleDragModeOnDblclick: false,
            });
        },

        zoom(value) {
            this.cropper && this.cropper.zoom(value);
        },

        rotate(deg) {
            this.cropper && this.cropper.rotate(deg);
        },

        apply() {
            if (! this.cropper) return;
            this.loading = true;

            const canvas = this.cropper.getCroppedCanvas({
                width: 512,
                height: 512,
                imageSmoothingQuality: 'high',
            });

            canvas.toBlob((blob) => {
                const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
                this.$wire.upload('photo', file,
                    () => { this.loading = false; this.close(); },
                    () => { this.loading = false; },
                );
            }, 'image/jpeg', 0.9);
        },

        close() {
            this.open = false;
            this.imageSrc = null;
            this.destroyCropper();
        },

        destroyCropper() {
            if (this.cropper) {
                this.cropper.destroy();
                this.cropper = null;
            }
        },
    }));

    window.Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),

        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        },
    });
});
