<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redaksi field sensitif (NIK, client_secret) dari context/extra log (ADR D3).
 * NIK adalah PII; tak boleh pernah muncul plaintext di log walau terlanjur
 * dimasukkan ke context.
 */
class RedactSensitiveLogContext implements ProcessorInterface
{
    /** @var list<string> */
    private const REDACTED_KEYS = ['nik', 'client_secret'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(mb_strtolower($key), self::REDACTED_KEYS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }
}
