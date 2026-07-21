<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Database\Eloquent\Model;

class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('avatar_path')
                    ->label('Foto Profil')
                    ->avatar()
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('avatars')
                    ->maxSize(2048),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $emailChanged = array_key_exists('email', $data) && $data['email'] !== $record->email;

        if ($emailChanged) {
            $data['email_verified_at'] = null;
        }

        $record = parent::handleRecordUpdate($record, $data);

        if ($emailChanged) {
            $record->sendEmailVerificationNotification();

            Notification::make()
                ->title('Link verifikasi telah dikirim ke email baru Anda.')
                ->success()
                ->send();
        }

        return $record;
    }
}
