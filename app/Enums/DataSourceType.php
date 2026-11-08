<?php

namespace App\Enums;

enum DataSourceType: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Mysql = 'mysql';
    case Postgres = 'postgres';
    case Rest = 'rest';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV upload',
            self::Xlsx => 'Excel upload',
            self::Mysql => 'MySQL',
            self::Postgres => 'PostgreSQL',
            self::Rest => 'REST API',
        };
    }

    /** File sources receive data through uploads; the others connect out. */
    public function isFile(): bool
    {
        return $this === self::Csv || $this === self::Xlsx;
    }

    public function isDatabase(): bool
    {
        return $this === self::Mysql || $this === self::Postgres;
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Mysql => 3306,
            self::Postgres => 5432,
            default => null,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
