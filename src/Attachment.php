<?php

declare(strict_types=1);

namespace Shado\React\Mailer;

use JsonSerializable;

final class Attachment implements JsonSerializable
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $name = null,
        public readonly ?string $mimeType = null,
        public readonly bool $inline = false,
    ) {}

    public function jsonSerialize(): array
    {
        return (array)$this;
    }
}