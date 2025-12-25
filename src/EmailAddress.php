<?php

declare(strict_types=1);

namespace Shado\React\Mailer;

use JsonSerializable;

final class EmailAddress implements JsonSerializable
{
    public function __construct(
        public readonly string $address,
        public readonly ?string $name = null,
    ) {}

    public function jsonSerialize(): array
    {
        return (array)$this;
    }
}