# ReactPHP Mailer

A simple, asynchronous email sending library built on top of [Symfony Mailer](https://symfony.com/doc/8.0/mailer.html) and [ReactPHP](https://reactphp.org/).

> [!WARNING]  
> This library is in early development stage. The API may change in future releases.

## Installation

You can install the library using Composer. [New to Composer?](https://getcomposer.org/doc/00-intro.md)

```bash
composer require shado/reactphp-mailer
```

## Usage

### Sending Emails

To send your first email, all you need to do is create a `Mailer` instance with the desired transport DSN.

```php
use Shado\React\Mailer\{ Email, EmailAddress, Factory };

$mailer = (new Factory())->create('smtp://user:pass@smtp.example.com:25');

$email = new Email(
    from: new EmailAddress(address: 'shado@example.com', name: 'Shado'),
    to: [new EmailAddress('someone-else@example.com')],
    subject: 'Message from ReactPHP Mailer',
    text: 'Hello! This is a test message 😊',
);

$mailer->send($email);
```

Under the hood, this library uses a thin abstraction based on a separate process worker. This worker keeps running in the 
background, so bootstrap time is minimal for subsequent email sends. At the same time, the worker can send only one email at 
a time. Subsequent `send()` calls are automatically queued until the previous message is sent. 

This is [just good enough](https://en.wikipedia.org/wiki/Principle_of_good_enough)
for most use cases, but if you need to send multiple emails concurrently, consider using the [shado/php-resource-pool](https://github.com/szado/php-resource-pool)
library, which allows you to create a pool of Mailer instances and manage them efficiently.

```php
use Shado\ResourcePool\{ ResourcePool, FactoryController };
use Shado\React\Mailer\{ Factory };

$factory = new Factory();
$pool = new ResourcePool(
    factory: function (FactoryController $controller) use ($factory) {
        $mailer = $factory->create('smtp://user:pass@smtp.example.com:25');
        // Detach instance when it dies - the pool will create a new one
        $mailer->on('dead', $controller->detach(...));
        return $mailer;
    },
    limit: 10, // Max 10 concurrent mailers
);

$mailer = $pool->borrow();
// Use $mailer...
$pool->return($mailer);
```

### `dead` event

`dead` event allows to be notified when the worker process has died unexpectedly.

```php
$mailer->on('dead', function () {
    echo "Mailer process has died unexpectedly.\n";
});
```

### Supported Transports

Please refer to the Symfony Mailer documentation for details about supported transports.

SMTP, Sendmail and native transports are supported [out of the box](https://symfony.com/doc/8.0/mailer.html#using-built-in-transports). 

Additionally, you can use third-party transports like (but not limited to) Mailgun, SendGrid and Amazon SES by [installing the corresponding Symfony packages](https://symfony.com/doc/8.0/mailer.html#using-a-3rd-party-transport).

## At the end...

This library was heavily inspired by [clue/reactphp-sqlite](https://github.com/clue/reactphp-sqlite), so big thanks to the
authors for the great work! 💜

- Run tests: `./vendor/bin/phpunit tests`.
- Feel free to create an issue or submit your PR! 🤗
- Licence: MIT.
