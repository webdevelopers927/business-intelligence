<?php

namespace App\Enums;

/** Only `completed` transactions count toward revenue. */
enum SalesStatus: string
{
    case Completed = 'completed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
    case Pending = 'pending';
}
