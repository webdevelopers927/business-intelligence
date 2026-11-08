<?php

namespace App\Enums;

enum ImportStatus: string
{
    /** Uploaded, awaiting column mapping. */
    case Draft = 'draft';
    /** Mapping confirmed, queued for processing. */
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Awaiting mapping',
            self::Pending => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed], true);
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
