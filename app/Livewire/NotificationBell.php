<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class NotificationBell extends Component
{
    public function markAsRead(string $id): void
    {
        auth()->user()?->notifications()
            ->whereNull('read_at')
            ->whereKey($id)
            ->update(['read_at' => now()]);
    }

    /**
     * Tandai dibaca lalu arahkan ke tautan notifikasi (bila ada). URL diambil
     * dari record di server (bukan dari client) agar tak bisa dipalsukan.
     */
    public function open(string $id)
    {
        $notification = auth()->user()?->notifications()->whereKey($id)->first();

        if ($notification === null) {
            return null;
        }

        $notification->markAsRead();

        $url = $notification->data['actions'][0]['url'] ?? null;

        if ($url) {
            return $this->redirect($url, navigate: true);
        }

        return null;
    }

    public function markAllAsRead(): void
    {
        auth()->user()?->unreadNotifications->markAsRead();
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.notification-bell', [
            'notifications' => $user
                ? $user->notifications()->latest()->limit(12)->get()
                : collect(),
            'unreadCount' => $user ? $user->unreadNotifications()->count() : 0,
        ]);
    }
}
