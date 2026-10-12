<?php

declare(strict_types=1);

namespace App\Models;

class Team
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $shortName = null;
    public ?string $logoUrl = null;
    public ?string $contactName = null;
    public ?string $contactPhone = null;
    public bool $isActive = true;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;
}
