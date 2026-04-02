<?php

namespace Tests\Unit;

use Assegai\Beanstalkd\BeanstalkQueue;
use Assegai\Common\Exceptions\QueueException;
use BadMethodCallException;
use PHPUnit\Framework\TestCase;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\JobStats;
use Pheanstalk\Values\ServerStats;
use Pheanstalk\Values\Timeout;
use Pheanstalk\Values\TubeList;
use Pheanstalk\Values\TubeName;
use Pheanstalk\Values\TubeStats;
use Psr\Log\NullLogger;
use RuntimeException;

final class BeanstalkQueueTest extends TestCase
{
  public function testCreateNormalizesTimeoutsAndDefaults(): void
  {
    $queue = InspectableBeanstalkQueue::create([
      'name' => 'notifications',
      'connection_timeout' => 10,
      'receive_timeout' => 5,
    ]);

    self::assertSame('notifications', $queue->captured['name']);
    self::assertSame(BeanstalkQueue::DEFAULT_PORT, $queue->captured['port']);
    self::assertInstanceOf(Timeout::class, $queue->captured['connectionTimeout']);
    self::assertInstanceOf(Timeout::class, $queue->captured['receiveTimeout']);
  }

  public function testAddPushesJsonWithExpectedDefaults(): void
  {
    $connection = new FakePheanstalkClient();

    $queue = new TestBeanstalkQueue();
    $queue->prime('notifications', $connection);

    $queue->add((object) ['task' => 'notify']);

    self::assertCount(1, $connection->putCalls);
    self::assertSame(
      [
        'data' => json_encode((object) ['task' => 'notify'], JSON_THROW_ON_ERROR),
        'priority' => PheanstalkPublisherInterface::DEFAULT_PRIORITY,
        'delay' => 30,
        'timeToRelease' => 60,
      ],
      $connection->putCalls[0]
    );
  }

  public function testProcessDeletesSuccessfulJobs(): void
  {
    $connection = new FakePheanstalkClient();
    $connection->reservedJob = new Job(new JobId('1'), '{"id":1}');

    $queue = new TestBeanstalkQueue();
    $queue->prime('notifications', $connection);

    $result = $queue->process(static fn (string $payload): array => json_decode($payload, true, 512, JSON_THROW_ON_ERROR));

    self::assertSame('notifications', $connection->watchedTube?->value);
    self::assertSame(1, $connection->deleteCalls);
    self::assertSame(0, $connection->releaseCalls);
    self::assertTrue($result->isOk());
    self::assertSame(['id' => 1], $result->getData());
  }

  public function testProcessReleasesFailedJobsWithoutDeletingThem(): void
  {
    $connection = new FakePheanstalkClient();
    $connection->reservedJob = new Job(new JobId('2'), '{"id":2}');

    $queue = new TestBeanstalkQueue();
    $queue->prime('notifications', $connection);

    $result = $queue->process(static function (): never {
      throw new RuntimeException('boom');
    });

    self::assertSame(1, $connection->releaseCalls);
    self::assertSame(0, $connection->deleteCalls);
    self::assertTrue($result->isError());
    self::assertInstanceOf(QueueException::class, $result->getNextError());
  }
}

final class TestBeanstalkQueue extends BeanstalkQueue
{
  public function __construct()
  {
  }

  public function prime(string $name, FakePheanstalkClient $connection): void
  {
    $this->name = $name;
    $this->connection = $connection;
    $this->tubeName = new TubeName($name);
    $this->logger = new NullLogger();
  }
}

final class InspectableBeanstalkQueue extends BeanstalkQueue
{
  public array $captured = [];

  public function __construct(
    string $name,
    ?string $host = null,
    ?int $port = null,
    ?Timeout $connectionTimeout = null,
    ?Timeout $receiveTimeout = null,
  ) {
    $this->captured = [
      'name' => $name,
      'host' => $host,
      'port' => $port,
      'connectionTimeout' => $connectionTimeout,
      'receiveTimeout' => $receiveTimeout,
    ];

    $this->name = $name;
  }
}

final class FakePheanstalkClient implements PheanstalkManagerInterface, PheanstalkPublisherInterface, PheanstalkSubscriberInterface
{
  public ?TubeName $watchedTube = null;
  public ?Job $reservedJob = null;
  public array $putCalls = [];
  public int $deleteCalls = 0;
  public int $releaseCalls = 0;

  public function disconnect(): void
  {
  }

  public function listTubeUsed(): TubeName
  {
    return $this->watchedTube ?? new TubeName('default');
  }

  public function put(
    string $data,
    int $priority = self::DEFAULT_PRIORITY,
    int $delay = self::DEFAULT_DELAY,
    int $timeToRelease = self::DEFAULT_TTR
  ): JobIdInterface {
    $this->putCalls[] = [
      'data' => $data,
      'priority' => $priority,
      'delay' => $delay,
      'timeToRelease' => $timeToRelease,
    ];

    return new JobId((string) count($this->putCalls));
  }

  public function useTube(TubeName $tube): void
  {
    $this->watchedTube = $tube;
  }

  public function delete(JobIdInterface $job): void
  {
    $this->deleteCalls++;
  }

  public function ignore(TubeName $tube): int
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function listTubesWatched(): TubeList
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function release(
    JobIdInterface $job,
    int $priority = PheanstalkPublisherInterface::DEFAULT_PRIORITY,
    int $delay = PheanstalkPublisherInterface::DEFAULT_DELAY
  ): void {
    $this->releaseCalls++;
  }

  public function reserve(): Job
  {
    if (!$this->reservedJob) {
      throw new BadMethodCallException('Not used in this test.');
    }

    return $this->reservedJob;
  }

  public function bury(JobIdInterface $job, int $priority = PheanstalkPublisherInterface::DEFAULT_PRIORITY): void
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function reserveJob(JobIdInterface $job): Job
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function reserveWithTimeout(int $timeout): ?Job
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function touch(JobIdInterface $job): void
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function watch(TubeName $tube): int
  {
    $this->watchedTube = $tube;

    return 1;
  }

  public function kick(int $max): int
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function kickJob(JobIdInterface $job): void
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function listTubes(): TubeList
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function pauseTube(TubeName $tube, int $delay): void
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function resumeTube(TubeName $tube): void
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function peek(JobIdInterface $job): Job
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function peekReady(): ?Job
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function peekDelayed(): ?Job
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function peekBuried(): ?Job
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function statsJob(JobIdInterface $job): JobStats
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function statsTube(TubeName $tube): TubeStats
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function stats(): ServerStats
  {
    throw new BadMethodCallException('Not used in this test.');
  }
}
