<?php

declare(strict_types=1);

namespace App\Models;

class Category
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $description = null;
    public bool $isActive = true;
    public ?string $createdAt = null;
}
