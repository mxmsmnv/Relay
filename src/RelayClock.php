<?php

declare(strict_types=1);

namespace ProcessWire;

final class RelayClock
{
    public static function localToUtc(string $value, string $timezone): \DateTimeImmutable
    {
        $zone = new \DateTimeZone($timezone);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
        $errors = \DateTimeImmutable::getLastErrors();

        if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) {
            throw new \InvalidArgumentException('Invalid local date and time.');
        }

        if ($date->format('Y-m-d\TH:i') !== $value) {
            throw new \InvalidArgumentException('The local date and time does not exist in this timezone.');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function utcToLocal(string $value, string $timezone, string $format = 'Y-m-d H:i'): string
    {
        if ($value === '') {
            return '';
        }

        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone($timezone))
            ->format($format);
    }

    public static function assertTimezone(string $timezone): string
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Unknown timezone.');
        }

        return $timezone;
    }
}
