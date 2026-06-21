<?php

namespace App\Livewire\Settings;

use App\Models\StoreClient;
use Illuminate\Support\Str;
use Livewire\Component;

class StoreClients extends Component
{
    /** Permission untuk reveal/copy secret (mirror Filament). */
    public const COPY_SECRET_PERMISSION = 'copy_store_client_secret';

    // Create
    public bool $showCreate = false;

    public string $newName = '';

    public bool $newCanRefund = false;

    // Reveal (copy kredensial, butuh password)
    public bool $showReveal = false;

    public ?string $revealId = null;

    public string $revealPassword = '';

    // Tampilan kredensial sekali-pakai (hasil create/regenerate/reveal)
    public bool $showCredential = false;

    public ?string $credClientId = null;

    public ?string $credSecret = null;

    public bool $credIsNew = false;

    public function createClient(): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:100'],
        ]);

        $secret = Str::random(40);

        $client = StoreClient::create([
            'name' => $this->newName,
            'client_id' => 'store_'.Str::lower(Str::random(20)),
            'client_secret' => $secret,            // di-hash otomatis oleh cast
            'client_secret_encrypted' => $secret,  // disimpan terenkripsi (reversible)
            'is_active' => true,
            'can_refund' => $this->newCanRefund,
        ]);

        $this->reset('showCreate', 'newName', 'newCanRefund');
        $this->presentCredential($client->client_id, $secret, isNew: true);
    }

    public function regenerate(string $id): void
    {
        $client = StoreClient::findOrFail($id);

        $secret = Str::random(40);
        $client->update([
            'client_secret' => $secret,
            'client_secret_encrypted' => $secret,
        ]);

        $this->presentCredential($client->client_id, $secret, isNew: true);
    }

    public function toggleActive(string $id): void
    {
        $client = StoreClient::findOrFail($id);
        $client->update(['is_active' => ! $client->is_active]);
    }

    public function toggleRefund(string $id): void
    {
        $client = StoreClient::findOrFail($id);
        $client->update(['can_refund' => ! $client->can_refund]);
    }

    public function deleteClient(string $id): void
    {
        StoreClient::findOrFail($id)->delete();
        $this->dispatch('toast', type: 'success', message: 'Klien toko dihapus.');
    }

    public function openReveal(string $id): void
    {
        abort_unless($this->canCopySecret(), 403);

        $this->reset('revealPassword');
        $this->revealId = $id;
        $this->showReveal = true;
    }

    public function confirmReveal(): void
    {
        abort_unless($this->canCopySecret(), 403);

        $this->validate([
            'revealPassword' => ['required', 'current_password'],
        ], [], ['revealPassword' => 'password']);

        $client = StoreClient::findOrFail($this->revealId);
        $secret = $client->client_secret_encrypted;

        if (blank($secret)) {
            $this->reset('showReveal', 'revealPassword', 'revealId');
            $this->dispatch('toast', type: 'danger', message: 'Secret belum tersedia. Lakukan "Reset Secret" lebih dulu.');

            return;
        }

        activity()
            ->causedBy(auth()->user())
            ->performedOn($client)
            ->event('reveal_secret')
            ->log("Copy kredensial klien toko {$client->name}");

        $this->reset('showReveal', 'revealPassword', 'revealId');
        $this->presentCredential($client->client_id, $secret, isNew: false);
    }

    public function closeCredential(): void
    {
        // Hapus secret dari state komponen segera setelah ditutup.
        $this->reset('showCredential', 'credClientId', 'credSecret', 'credIsNew');
    }

    private function presentCredential(string $clientId, string $secret, bool $isNew): void
    {
        $this->credClientId = $clientId;
        $this->credSecret = $secret;
        $this->credIsNew = $isNew;
        $this->showCredential = true;
    }

    public function canCopySecret(): bool
    {
        return auth()->user()?->can(self::COPY_SECRET_PERMISSION) ?? false;
    }

    public function render()
    {
        return view('livewire.settings.store-clients', [
            'clients' => StoreClient::query()->latest()->get(),
            'canCopy' => $this->canCopySecret(),
        ]);
    }
}
