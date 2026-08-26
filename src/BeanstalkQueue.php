<?php

namespace Assegai\Beanstalkd;

use Assegai\Common\Exceptions\QueueException;
use Assegai\Common\Interfaces\Queues\QueueJobCodecInterface;
use Assegai\Common\Interfaces\Queues\QueueInterface;
use Assegai\Common\Interfaces\Queues\QueueProcessResultInterface;
use Assegai\Common\Queues\JsonQueueJobCodec;
use Assegai\Common\Queues\QueueJobTypeResolver;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Timeout;
use Pheanstalk\Values\TubeName;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Class BeanstalkQueue
 *
 * Represents a Beanstalk queue implementation.
 * @implements QueueInterface<object>
 */
class BeanstalkQueue implements QueueInterface
{
  public const string DEFAULT_HOST = 'localhost';
  /**
   * @var int The default port for Beanstalk.
   */
  public const int DEFAULT_PORT = 11300;
  /**
   * @var (PheanstalkManagerInterface&PheanstalkPublisherInterface&PheanstalkSubscriberInterface)|null The connection to the Beanstalk server.
   */
  protected (PheanstalkManagerInterface&PheanstalkPublisherInterface&PheanstalkSubscriberInterface)|null $connection = null;
  /**
   * @var TubeName The name of the tube (queue) in Beanstalk.
   */
  protected TubeName $tubeName;
  /**
   * @var LoggerInterface The logger for logging messages.
   */
  protected LoggerInterface $logger;

  /**
   * @param string $name
   * @param string|null $host
   * @param int|null $port
   * @param Timeout|null $connectionTimeout
   * @param Timeout|null $receiveTimeout
   * @param QueueJobCodecInterface|null $jobCodec
   * @param int $reserveTimeout Seconds to wait for one available job.
   * @param int $retryPriority Priority applied when releasing a failed job.
   * @param int $retryDelay Seconds before a released job becomes ready again.
   */
  public function __construct(
    protected string $name,
    protected ?string $host = null,
    protected ?int $port = null,
    protected ?Timeout $connectionTimeout = null,
    protected ?Timeout $receiveTimeout = null,
    ?QueueJobCodecInterface $jobCodec = null,
    protected int $reserveTimeout = 0,
    protected int $retryPriority = PheanstalkPublisherInterface::DEFAULT_PRIORITY,
    protected int $retryDelay = PheanstalkPublisherInterface::DEFAULT_DELAY,
  )
  {
    $this->logger = new ConsoleLogger(new ConsoleOutput());
    $this->jobCodec = $jobCodec ?? new JsonQueueJobCodec();
    $this->tubeName = new TubeName($this->name);
  }

  protected QueueJobCodecInterface $jobCodec;

  /**
   * @inheritDoc
   *
   * @throws QueueException
   */
  public function add(object $job, object|array|null $options = null): void
  {
    try {
      $priority = (int) $this->option($options, 'priority', PheanstalkPublisherInterface::DEFAULT_PRIORITY);
      $delay = (int) $this->option($options, 'delay', 30);
      $timeToRelease = (int) $this->option($options, 'time_to_release', 60);

      $this->connection()->put(
        data: $this->jobCodec->encode($job),
        priority: $priority,
        delay: $delay,
        timeToRelease: $timeToRelease
      );
    } catch (Throwable $throwable) {
      throw $this->queueException('Failed to add Beanstalkd job.', $throwable);
    }
  }

  /**
   * @inheritDoc
   * @throws QueueException
   */
  public function process(callable $callback): QueueProcessResultInterface
  {
    $reservedJob = null;
    $job = null;
    $callbackSucceeded = false;
    $connection = null;

    try {
      $connection = $this->connection();
      $watchedTubeCount = $connection->watch($this->tubeName);

      if ($this->name !== 'default' && $watchedTubeCount > 1) {
        $connection->ignore(new TubeName('default'));
      }

      $reservedJob = $connection->reserveWithTimeout(max(0, $this->reserveTimeout));

      if ($reservedJob === null) {
        return new BeanstalkQueueProcessResult();
      }

      $payload = $reservedJob->getData();
      $job = $this->jobCodec->decode(
        $payload,
        QueueJobTypeResolver::fromCallback($callback),
      );

      $this->logger->info("Processing job: " . $payload);
      $data = $callback($job);
      $callbackSucceeded = true;
      $connection->delete($reservedJob);

      return new BeanstalkQueueProcessResult(data: $data, job: $job);
    } catch (Throwable $throwable) {
      $this->logger->error("Failed to process job: " . $throwable->getMessage());
      $errors = [$this->queueException('Queue processing failed.', $throwable)];

      if ($connection !== null && $reservedJob !== null && !$callbackSucceeded) {
        try {
          $connection->release($reservedJob, $this->retryPriority, $this->retryDelay);
        } catch (Throwable $settlementError) {
          $errors[] = $this->queueException('Failed to release Beanstalkd job.', $settlementError);
        }
      }

      return new BeanstalkQueueProcessResult(
        errors: $errors,
        job: $job,
      );
    }
  }

  /**
   * @inheritDoc
   */
  public function getName(): string
  {
    return $this->name;
  }

  /**
   * @inheritDoc
   * @throws QueueException
   */
  public function getTotalJobs(): int
  {
    try {
      return $this->connection()->statsTube($this->tubeName)->currentJobsReady;
    } catch (Throwable $throwable) {
      throw $this->queueException('Failed to get total jobs.', $throwable);
    }
  }

  /**
   * @inheritDoc
   * @throws QueueException
   */
  public static function create(array $config): self
  {
    $name = $config['name'] ?? 'default';

    $connectionTimeout  = $config['connection_timeout'] ?? null;
    $receiveTimeout     = $config['receive_timeout'] ?? null;

    if ($connectionTimeout) {
      $connectionTimeout = new Timeout($connectionTimeout);
    }

    if ($receiveTimeout) {
      $receiveTimeout = new Timeout($receiveTimeout);
    }

    $jobCodec = $config['job_codec'] ?? null;

    if ($jobCodec !== null && !$jobCodec instanceof QueueJobCodecInterface) {
      throw new QueueException('Beanstalkd job_codec must implement QueueJobCodecInterface.');
    }

    return new static(
      $name,
      $config['host'] ?? null,
      $config['port'] ?? BeanstalkQueue::DEFAULT_PORT,
      $connectionTimeout,
      $receiveTimeout,
      $jobCodec,
      max(0, (int) ($config['reserve_timeout'] ?? 0)),
      (int) ($config['retry_priority'] ?? PheanstalkPublisherInterface::DEFAULT_PRIORITY),
      max(0, (int) ($config['retry_delay'] ?? PheanstalkPublisherInterface::DEFAULT_DELAY)),
    );
  }

  /**
   * Returns the active client, connecting and selecting the tube on first use.
   *
   * A failed attempt is not cached, allowing a later queue operation to retry.
   *
   * @return PheanstalkManagerInterface&PheanstalkPublisherInterface&PheanstalkSubscriberInterface
   * @throws QueueException
   */
  protected function connection(): PheanstalkManagerInterface&PheanstalkPublisherInterface&PheanstalkSubscriberInterface
  {
    if ($this->connection !== null) {
      return $this->connection;
    }

    try {
      $connection = $this->createConnection();
      $connection->useTube($this->tubeName);

      return $this->connection = $connection;
    } catch (Throwable $throwable) {
      throw $this->queueException('Failed to connect to Beanstalkd.', $throwable);
    }
  }

  /** @return PheanstalkManagerInterface&PheanstalkPublisherInterface&PheanstalkSubscriberInterface */
  protected function createConnection(): PheanstalkManagerInterface&PheanstalkPublisherInterface&PheanstalkSubscriberInterface
  {
    return Pheanstalk::create(
      $this->host ?? self::DEFAULT_HOST,
      $this->port ?? self::DEFAULT_PORT,
      $this->connectionTimeout,
      $this->receiveTimeout,
    );
  }

  private function option(object|array|null $options, string $name, mixed $default): mixed
  {
    if (is_array($options)) {
      return $options[$name] ?? $default;
    }

    return $options?->{$name} ?? $default;
  }

  private function queueException(string $message, Throwable $throwable): QueueException
  {
    if ($throwable instanceof QueueException) {
      return $throwable;
    }

    return new QueueException($message . ' ' . $throwable->getMessage(), (int) $throwable->getCode(), $throwable);
  }
}
