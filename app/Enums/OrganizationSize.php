<?php

namespace App\Enums;

enum OrganizationSize: string
{
    case Micro = '1_10';
    case Small = '11_50';
    case Medium = '51_200';
    case Large = '201_1000';
    case Enterprise = '1000_plus';

    public function label(): string
    {
        return match ($this) {
            self::Micro => '1–10 people',
            self::Small => '11–50 people',
            self::Medium => '51–200 people',
            self::Large => '201–1,000 people',
            self::Enterprise => '1,000+ people',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
