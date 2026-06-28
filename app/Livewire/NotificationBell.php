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
