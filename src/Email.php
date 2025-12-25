<?php

declare(strict_types=1);

namespace Shado\React\Mailer;

use InvalidArgumentException;
use JsonSerializable;
use Symfony\Component\Mime\Address;

final class Email implements JsonSerializable
{
    /**
     * @internal
     */
    public static function fromArray(array $array): self {
        $array['to'] = \array_map(fn($array) => new Address(...$array), $array['to']);
        $array['cc'] = \array_map(fn($array) => new Address(...$array), $array['cc']);
        $array['bcc'] = \array_map(fn($array) => new Address(...$array), $array['bcc']);
        $array['replyTo'] = \array_map(fn($array) => new Address(...$array), $array['replyTo']);
        $array['attachments'] = \array_map(fn($array) => new Attachment(...$array), $array['attachments']);
        return new self(...$array);
    }

    public function __construct(
        public EmailAddress $from,

        /** @var list<EmailAddress> */
        public array $to = [],

        /** @var list<EmailAddress> */
        public array $cc = [],

        /** @var list<EmailAddress> */
        public array $bcc = [],

        /** @var list<EmailAddress> */
        public array $replyTo = [],

        public ?string $subject = null,

        public ?string $text = null,

        public ?string $html = null,

        /** @var list<Attachment> */
        public array $attachments = [],
    ) {
        if (empty($to) && empty($cc) && empty($bcc)) {
            throw new InvalidArgumentException('At least one recipient (to, cc, bcc) must be specified.');
        }
    }

    public function jsonSerialize(): array
    {
        return (array)$this;
    }
}