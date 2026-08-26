<div align="center" style="padding-bottom: 48px">
    <a href="https://assegaiphp.com/" target="blank"><img src="https://assegaiphp.com/images/logos/logo-cropped.png" width="200" alt="Assegai Logo"></a>
</div>

<p align="center">
  <a href="https://github.com/assegaiphp/beanstalkd/releases"><img alt="Latest release" src="https://img.shields.io/github/v/release/assegaiphp/beanstalkd?display_name=tag&sort=semver&style=flat-square"></a>
  <a href="https://github.com/assegaiphp/beanstalkd/actions/workflows/php.yml"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/assegaiphp/beanstalkd/php.yml?branch=main&label=tests&style=flat-square"></a>
  <img alt="PHP 8.4+" src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=flat-square&logo=php&logoColor=white">
  <a href="https://github.com/assegaiphp/beanstalkd/blob/main/LICENSE"><img alt="License" src="https://img.shields.io/github/license/assegaiphp/beanstalkd?style=flat-square"></a>
  <img alt="Status active" src="https://img.shields.io/badge/status-active-10b981?style=flat-square">
</p>

<p align="center">Beanstalkd queue support for AssegaiPHP applications.</p>

## Description

This package integrates [Beanstalkd](https://github.com/beanstalkd/beanstalkd) with AssegaiPHP through [Pheanstalk](https://github.com/pda/pheanstalk). It serializes queued domain jobs, hydrates them for typed processors, and settles reserved jobs according to the processor outcome.

## Contribution workflow

For commit and pull request conventions in this repo, see:

- [docs/commit-and-pr-guidelines.md](./docs/commit-and-pr-guidelines.md)

## Installation

Install the package with Composer:

```bash
$ composer require assegaiphp/beanstalkd
```

Inside an Assegai workspace, the Console can install and configure it:

```bash
$ assegai add beanstalkd
```

## Compatibility

| Beanstalkd package | AssegaiPHP Common |
| --- | --- |
| `>=1.1.1 <2.0` | `^0.10.1` |
| `1.1.0` | `^0.10.0` |
| `1.0.x` | `^0.9.0` |

Upgrade this package and its coordinated first-party dependencies together when moving between AssegaiPHP release lines.

## Configuration

Register the driver and its connections in `config/queues.php`:

```php
<?php

use Assegai\Beanstalkd\BeanstalkQueue;

return [
  'drivers' => [
    'beanstalk' => BeanstalkQueue::class,
  ],
  'connections' => [
    'beanstalk' => [
      'notifications' => [
        'host' => 'localhost',
        'port' => 11300,
        'connection_timeout' => 10,
        'receive_timeout' => 10,
        'reserve_timeout' => 0,
        'retry_priority' => 1024,
        'retry_delay' => 15,
      ],
    ],
  ],
];
```

Queue references use the `driver.connection` format, such as `beanstalk.notifications`.

Each worker poll watches only the configured tube and reserves at most one job. Successful processing deletes the job. A decoding or processor failure releases it with `retry_priority` and `retry_delay`. Use a non-zero retry delay in production to avoid a tight failure loop.

## Producing jobs

Inject a configured queue using `#[InjectQueue]` and add a domain job:

```php
<?php

use Assegai\Common\Interfaces\Queues\QueueInterface;
use Assegai\Core\Attributes\Injectable;
use Assegai\Core\Queues\Attributes\InjectQueue;

final readonly class NotificationJob
{
  public function __construct(
    public string $recipient,
    public string $message,
  ) {
  }
}

#[Injectable]
readonly class NotificationsService
{
  public function __construct(
    #[InjectQueue('beanstalk.notifications')] private QueueInterface $queue,
  ) {
  }

  public function send(NotificationJob $job): void
  {
    $this->queue->add($job);
  }
}
```

The driver writes a versioned JSON envelope containing the job class and payload.

## Consuming jobs

Define an injectable processor whose method declares the job type it accepts:

```php
<?php

use Assegai\Core\Attributes\Injectable;
use Assegai\Core\Queues\Attributes\QueueProcessor;

#[Injectable]
#[QueueProcessor('beanstalk.notifications')]
final class NotificationsProcessor
{
  public function process(NotificationJob $job): void
  {
    // Handle the notification.
  }
}
```

Register the processor in its module's provider list so the Console can discover it. The worker validates the envelope class against the processor parameter and hydrates the domain object before invocation. Legacy JSON messages are hydrated when the processor declares a concrete class; a processor typed only as `object` receives `stdClass`.

Generate a processor with the Console when you want a starter class:

```bash
$ assegai g qp notifications --queue=beanstalk.notifications
$ assegai g qp notifications --queue=beanstalk.notifications --job=Jobs/NotificationJob
```

## Running workers

Discover and run queue processors with the Assegai Console:

```bash
$ assegai queue:list
$ assegai queue:work beanstalk.notifications
```

Process at most one available job and exit with `--once`:

```bash
$ assegai queue:work beanstalk.notifications --once
```

If multiple processors target the same queue, use `--processor` to select one. See the [AssegaiPHP queue guide](https://assegaiphp.com/guide/advanced-topics/queues-and-background-jobs) for application-level worker guidance.

## Testing

Run the package test suite with Composer:

```bash
$ composer test
```

## Resources

- [Beanstalkd protocol](https://github.com/beanstalkd/beanstalkd)
- [Pheanstalk PHP client](https://github.com/pda/pheanstalk)
- [AssegaiPHP framework](https://github.com/assegaiphp/framework)

## Support

Assegai is an MIT-licensed open source project. It can grow thanks to sponsors and support by the amazing backers. If you'd like to join them, please [read more here](https://assegaiphp.com/support).

## Stay in touch

- Author - [Andrew Masiye](https://twitter.com/feenix11)
- Website - [https://assegaiphp.com](https://assegaiphp.com/)
- Twitter - [@assegaiphp](https://twitter.com/assegaiphp)

## License

Assegai is [MIT licensed](LICENSE).
