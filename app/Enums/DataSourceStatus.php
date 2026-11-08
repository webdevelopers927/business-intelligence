<?php

namespace App\Enums;

enum DataSourceStatus: string
{
    /** Connection sources that have not passed a connection test yet. */
    case Pending = 'pending';
    case Active = 'active';
    case Error = 'error';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not tested',
            self::Active => 'Active',
            self::Error => 'Error',
            self::Disabled => 'Disabled',
        };
    }
}
