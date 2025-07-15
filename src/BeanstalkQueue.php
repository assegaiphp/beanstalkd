<?php

namespace Assegaiphp\Beanstalkd;

use Assegai\Common\Exceptions\QueueException;
use Assegai\Common\Interfaces\Queues\QueueInterface;
use Assegai\Common\Interfaces\Queues\QueueProcessResultInterface;
use Exception;
use JsonException;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Timeout;
use Pheanstalk\Values\TubeName;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

class BeanstalkQueue implements QueueInterface
{
  /**
   * @var int The default port for Beanstalk.
   */
  public const int DEFAULT_PORT = 11300;
  /**
   * @var Pheanstalk The connection to the Beanstalk server.
   */
  protected Pheanstalk $connection;
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
   * @throws QueueException
   */
  public function __construct(
    protected string $name,
    protected ?string $host = null,
    protected ?int $port = null,
    protected ?Timeout $connectionTimeout = null,
    protected ?Timeout $receiveTimeout = null,
  )
  {
    $this->logger = new ConsoleLogger(new ConsoleOutput());

    try {
      $this->connection = Pheanstalk::create($this->host, $this->port, $this->connectionTimeout, $this->receiveTimeout);

      $this->tubeName = new TubeName($this->name);
      $this->connection->useTube($this->tubeName);
    } catch (Exception $exception) {
      throw new QueueException($exception->getMessage(), $exception->getCode(), $exception);
    }
  }

  /**
   * @inheritDoc
   *
   * @throws JsonException
   */
  public function add(object $job, object|array|null $options = null): void
  {
    $priority = PheanstalkPublisherInterface::DEFAULT_PRIORITY;
    $delay = 30;
    $timeToRelease = 60;

    $this->connection->put(
      data: json_encode($job, JSON_THROW_ON_ERROR),
      priority: $priority,
      delay: $delay,
      timeToRelease: $timeToRelease
    );
  }

  /**
   * @inheritDoc
   * @throws QueueException
   */
  public function process(callable $callback): QueueProcessResultInterface
  {
    $this->connection->watch($this->tubeName);
    $job = $this->connection->reserve(); // Wait for a job to be available

    try {
      $payload = $job->getData();

      $this->logger->info("Processing job: " . $payload);
      $result = new BeanstalkQueueProcessResult($callback($payload));
    } catch(Exception $exception) {
      $this->logger->error("Failed to process job: " . $exception->getMessage());
      $this->connection->release($job);
      $result = new BeanstalkQueueProcessResult(
        errors: [new QueueException("Queue processing failed!", $exception->getCode(), $exception)]
      );
    }

    $this->connection->delete($job); // Delete the job after processing
    return $result;
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
      return $this->connection->statsTube($this->tubeName)->currentJobsReady;
    } catch (Exception $exception) {
      throw new QueueException("Failed to get total jobs: " . $exception->getMessage(), $exception->getCode(), $exception);
    }
  }

  /**
   * @inheritDoc
   * @throws QueueException
   */
  public static function create(array $config): self
  {
    $name ??= $config['name'] ?? 'default';

    $connectionTimeout  = $config['connection_timeout'] ?? null;
    $receiveTimeout     = $config['receive_timeout'] ?? null;

    if ($connectionTimeout) {
      $connectionTimeout = new Timeout($connectionTimeout);
    }

    if ($receiveTimeout) {
      $receiveTimeout = new Timeout($receiveTimeout);
    }

    return new self(
      $name,
      $config['host'] ?? null,
      $config['port'] ?? BeanstalkQueue::DEFAULT_PORT,
      $connectionTimeout,
      $receiveTimeout
    );
  }
}