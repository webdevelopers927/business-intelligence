<?php

namespace App\Enums;

enum Industry: string
{
    case Retail = 'retail';
    case Distribution = 'distribution';
    case ProfessionalServices = 'professional_services';
    case Manufacturing = 'manufacturing';
    case Hospitality = 'hospitality';
    case Technology = 'technology';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Retail => 'Retail',
            self::Distribution => 'Distribution & wholesale',
            self::ProfessionalServices => 'Professional services',
            self::Manufacturing => 'Manufacturing',
            self::Hospitality => 'Hospitality',
            self::Technology => 'Technology',
            self::Other => 'Other',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
