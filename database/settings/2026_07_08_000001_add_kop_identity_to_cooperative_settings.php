<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Identitas koperasi untuk kop + blok tanda tangan laporan PDF (ADR item 7).
        $this->migrator->add('cooperative.cooperative_address', null);
        $this->migrator->add('cooperative.cooperative_city', null);
        $this->migrator->add('cooperative.cooperative_phone', null);
        $this->migrator->add('cooperative.signatory_name', null);
        $this->migrator->add('cooperative.signatory_position', null);
    }

    public function down(): void
    {
        $this->migrator->delete('cooperative.cooperative_address');
        $this->migrator->delete('cooperative.cooperative_city');
        $this->migrator->delete('cooperative.cooperative_phone');
        $this->migrator->delete('cooperative.signatory_name');
        $this->migrator->delete('cooperative.signatory_position');
    }
};
