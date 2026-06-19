<?php

use App\Logging\RedactSensitiveLogContext;
use Monolog\Level;
use Monolog\LogRecord;

function makeRecord(array $context): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'test',
        context: $context,
    );
}

it('redacts nik at the top level of context', function () {
    $record = (new RedactSensitiveLogContext)(makeRecord(['nik' => '3201234567890001', 'member_id' => 'abc']));

    expect($record->context['nik'])->toBe('[REDACTED]')
        ->and($record->context['member_id'])->toBe('abc');
});

it('redacts nik and client_secret nested inside arrays', function () {
    $record = (new RedactSensitiveLogContext)(makeRecord([
        'payload' => ['nik' => '3201234567890001', 'amount' => '50000'],
        'client_secret' => 'super-secret',
    ]));

    expect($record->context['payload']['nik'])->toBe('[REDACTED]')
        ->and($record->context['payload']['amount'])->toBe('50000')
        ->and($record->context['client_secret'])->toBe('[REDACTED]');
});

it('is case-insensitive on key names', function () {
    $record = (new RedactSensitiveLogContext)(makeRecord(['NIK' => '3201234567890001']));

    expect($record->context['NIK'])->toBe('[REDACTED]');
});
