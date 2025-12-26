<?php

namespace Shado\Tests\React\Mailer;

use Shado\React\Mailer\Attachment;
use Shado\React\Mailer\Email;
use PHPUnit\Framework\TestCase;
use Shado\React\Mailer\EmailAddress;

class EmailTest extends TestCase
{
    public function testFromArray()
    {
        $emailArray = json_decode(json_encode($this->createEmail()), associative: true);
        $this->assertInstanceOf(Email::class, Email::fromArray($emailArray));
    }

    private function createEmail(): Email
    {
        return new Email(
            from: new EmailAddress('shado@example.com', 'Shado'),
            to: $this->createEmailArray(),
            cc: $this->createEmailArray(),
            bcc: $this->createEmailArray(),
            replyTo: $this->createEmailArray(),
            subject: 'Test Email',
            text: 'This is a test email.',
            html: '<p>This is a test email.</p>',
            attachments: [new Attachment('file.txt', 'text/plain', 'SGVsbG8gd29ybGQ=')],
        );
    }

    private function createEmailArray(): array
    {
        return [
            new EmailAddress('someone@example.com', 'Someone'),
            new EmailAddress('noone@example.com'),
        ];
    }
}
