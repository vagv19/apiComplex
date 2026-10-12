<?php

declare(strict_types=1);

namespace App\Models;

class Player
{
    public ?int $id = null;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $birthDate = null;
    public ?string $photoUrl = null;
    public ?string $phone = null;
    public ?string $email = null;
    public bool $isActive = true;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;
}
