<div align="center" style="padding-bottom: 48px">
    <a href="https://assegaiphp.com/" target="blank"><img src="https://assegaiphp.com/images/logos/logo-cropped.png" width="200" alt="Assegai Logo"></a>
</div>

<p style="text-align: center">A progressive <a href="https://php.net">PHP</a> framework for building effecient and scalable server-side applications.</p>


# AssegaiPHP Beanstalkd Queue Integration

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![AssegaiPHP](https://img.shields.io/badge/built%20for-AssegaiPHP-forestgreen)](https://github.com/assegaiphp/framework)

This package adds **Beanstalkd queue support** to the [AssegaiPHP](https://github.com/assegaiphp/framework) framework using the [Pheanstalk](https://github.com/pda/pheanstalk) PHP client.

---

## 📦 Installation

Install via Composer:

```bash
composer require assegaiphp/beanstalkd
````

Or use the Assegai CLI:

```bash
assegai add beanstalkd
```

---

## ⚙️ Configuration

Add a Beanstalk driver and connection to your `config/queues.php` file:

```php
<?php

return [
  'drivers' => [
    'beanstalk' => Assegai\Beanstalkd\BeanstalkdQueue::class,
  ],
  'connections' => [
    'beanstalk' => [
      'notifications' => [
        'host' => 'localhost',
        'port' => 11300,
        'connection_timeout' => 10,
        'receive_timeout' => 10,
      ],
    ],
  ],
];
```

> 💡 The format is: `'driverName.queueName'`, e.g., `'beanstalk.notifications'`.

---

## ✨ Usage

### Producing Jobs

Inject a queue instance in your service using `#[InjectQueue]`:

```php
use Assegai\Core\Queues\Attributes\InjectQueue;
use Assegai\Core\Queues\Interfaces\QueueInterface;

readonly class NotificationsService
{
  public function __construct(
    #[InjectQueue('beanstalk.notifications')] private QueueInterface $queue
  ) {}

  public function send(array $payload): void
  {
    $this->queue->add($payload);
  }
}
```

---

### Consuming Jobs

Create a queue consumer class with `#[Processor]` and extend `WorkerHost`:

```php
use Assegai\Core\Queues\Attributes\Processor;
use Assegai\Core\Queues\WorkerHost;
use Assegai\Core\Queues\QueueProcessResult;
use Assegai\Core\Queues\Interfaces\QueueProcessResultInterface;

#[Processor('beanstalk.notifications')]
class NotificationsConsumer extends WorkerHost
{
  public function process(callable $callback): QueueProcessResultInterface
  {
    $job = $callback();
    $data = $job->data;

    echo "Dispatching notification: {$data->message}" . PHP_EOL;

    return new QueueProcessResult(data: ['status' => 'sent'], job: $job);
  }
}
```

> ⚠️ Do not use `#[Injectable]` on consumers. The `process()` method must accept a `callable` and return a `QueueProcessResultInterface`.

---

### Running the Worker

Start the queue worker using:

```bash
assegai queue:work
```

This will continuously listen for jobs from the configured Beanstalk tube.

---

## 🧪 Testing

You can simulate jobs by calling the service from a controller or CLI command and watch the consumer terminal for output.

---

## 📚 Resources

* [Beanstalkd Protocol](https://github.com/beanstalkd/beanstalkd)
* [Pheanstalk PHP Client](https://github.com/pda/pheanstalk)
* [AssegaiPHP Documentation](https://github.com/assegaiphp/framework)

---

## Support

Assegai is an MIT-licensed open source project. It can grow thanks to sponsors and support by the amazing backers. If you'd like to join them, please [read more here](https://assegaiphp.com/support).

## Stay in touch

* Author - [Andrew Masiye](https://twitter.com/feenix11)
* Website - [https://assegaiphp.com](https://assegaiphp.com/)
* Twitter - [@assegaiphp](https://twitter.com/assegaiphp)

## License

Assegai is [MIT licensed](LICENSE).