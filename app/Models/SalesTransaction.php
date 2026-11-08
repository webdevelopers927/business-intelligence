<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\SalesChannel;
use App\Enums\SalesStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order header. Totals equal the sum of its items (recomputed after imports).
 *
 * @property int $id
 * @property string $reference
 * @property SalesStatus $status
 * @property string $net_amount
 * @property int $items_count
 */
class SalesTransaction extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'business_date' => 'date',
            'status' => SalesStatus::class,
            'channel' => SalesChannel::class,
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    /** @return HasMany<SalesTransactionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesTransactionItem::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
