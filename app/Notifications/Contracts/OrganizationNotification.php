<?php

namespace App\Notifications\Contracts;

interface OrganizationNotification
{
    public function organizationId(): ?int;
}
