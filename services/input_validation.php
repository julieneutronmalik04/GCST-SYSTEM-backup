<?php
function strictDate(string $value, string $label = 'Date'): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException("$label must be a valid calendar date.");
    return $value;
}
function strictTime(string $value, string $label = 'Time'): string {
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value)) throw new InvalidArgumentException("$label must be a valid time.");
    return strlen($value) === 5 ? "$value:00" : $value;
}
function validateAssetCode(string $value): string {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,19}$/D', $value)) throw new InvalidArgumentException('Codes must be 1–20 letters, digits, dots, underscores or hyphens, starting with a letter or digit.');
    return $value;
}
