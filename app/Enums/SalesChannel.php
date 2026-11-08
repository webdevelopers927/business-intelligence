<?php

namespace App\Enums;

enum SalesChannel: string
{
    case Store = 'store';
    case Online = 'online';
    case Wholesale = 'wholesale';
}
