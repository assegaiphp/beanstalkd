<?php

namespace Tests\Unit;

use Assegai\Beanstalkd\BeanstalkQueue;
use Assegai\Common\Exceptions\QueueException;
use Assegai\Common\Interfaces\Queues\QueueJobCodecInterface;
use Assegai\Common\Queues\JsonQueueJobCodec;
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
    self::assertSame(0, $queue->captured['reserveTimeout']);
    self::assertSame(PheanstalkPublisherInterface::DEFAULT_PRIORITY, $queue->captured['retryPriority']);
    self::assertSame(PheanstalkPublisherInterface::DEFAULT_DELAY, $queue->captured['retryDelay']);
  }

  public function testCreateForwardsCodecAndRetryConfiguration(): void
  {
    $codec = new JsonQueueJobCodec();
    $queue = InspectableBeanstalkQueue::create([
      'name' => 'notifications',
      'job_codec' => $codec,
      'reserve_timeout' => 3,
      'retry_priority' => 512,
      'retry_delay' => 15,
    ]);

    self::assertSame($codec, $queue->captured['jobCodec']);
    self::assertSame(3, $queue->captured['reserveTimeout']);
    self::assertSame(512, $queue->captured['retryPriority']);
    self::assertSame(15, $queue->captured['retryDelay']);
  }

  public function testAddPushesJsonWithExpectedDefaults(): void
  {
    $connection = new FakePheanstalkClient();

    $queue = new TestBeanstalkQueue();
    $queue->prime('notifications', $connection);

    $queue->add(new BeanstalkTestJob('notify'));

    self::assertCount(1, $connection->putCalls);
    $payload = json_decode($connection->putCalls[0]['data'], true, 512, JSON_THROW_ON_ERROR);
    self::assertSame(BeanstalkTestJob::class, $payload['_assegai_queue']['job']);
    self::assertSame('notify', $payload['payload']['task']);
    self::assertSame(PheanstalkPublisherInterface::DEFAULT_PRIORITY, $connection->putCalls[0]['priority']);
    self::assertSame(30, $connection->putCalls[0]['delay']);
    self::assertSame(60, $connection->putCalls[0]['timeToRelease']);
  }

  public function testProcessDeletesSuccessfulJobs(): void
  {
    $connection = new FakePheanstalkClient();
    $connection->reservedJob = new Job(new JobId('1'), '{"task":"notify"}');

    $queue = new TestBeanstalkQueue();
    $queue->prime('notifications', $connection);

    $result = $queue->process(static fn (BeanstalkTestJob $job): string => strtoupper($job->task));

    self::assertSame('notifications', $connection->watchedTube?->value);
    self::assertSame(['default'], $connection->ignoredTubes);
    self::assertSame([0], $connection->reserveTimeoutCalls);
    self::assertSame(1, $connection->deleteCalls);
    self::assertSame([], $connection->releaseCalls);
    self::assertTrue($result->isOk());
    self::assertSame('NOTIFY', $result->getData());
    self::assertInstanceOf(BeanstalkTestJob::class, $result->getJob());
    self::assertSame('notify', $result->getJob()?->task);
  }

  public function testProcessReleasesEveryThrowableWithoutDeletingTheJob(): void
  {
    $connection = new FakePheanstalkClient();
    $connection->reservedJob = new Job(new JobId('2'), '{"task":"notify"}');

    $queue = new TestBeanstalkQueue();
    $queue->prime('notifications', $connection, retryPriority: 512, retryDelay: 15);

    $result = $queue->process(static function (BeanstalkTestJob $job): never {
      throw new \TypeError('boom');
    });

    self::assertSame([['job' => $connection->reservedJob, 'priority' => 512, 'delay' => 15]], $connection->releaseCalls);
    self::assertSame(0, $connection->deleteCalls);
    self::assertTrue($result->isError());
    self::assertInstanceOf(QueueException::class, $result->getNextError());
    self::assertInstanceOf(\TypeError::class, $result->getNextError()?->getPrevious());
    self::assertInstanceOf(BeanstalkTestJob::class, $result->getJob());
  }

  public function testProcessReturnsAnEmptyResultWhenReserveTimesOut(): void
  {
    $connection = new FakePheanstalkClient();
    $queue = new TestBeanstalkQueue();
    $queue->prime('notifications', $connection, reserveTimeout: 2);

    $result = $queue->process(static fn (BeanstalkTestJob $job): null => null);

    self::assertSame([2], $connection->reserveTimeoutCalls);
    self::assertTrue($result->isOk());
    self::assertNull($result->getJob());
  }

  public function testProcessReleasesMalformedJobsBeforeCallingTheProcessor(): void
  {
    $connection = new FakePheanstalkClient();
    $connection->reservedJob = new Job(new JobId('3'), '{invalid-json');

    $queue = new TestBeanstalkQueue();
    $queue->prime('notifications', $connection, retryPriority: 512, retryDelay: 15);
    $processorCalled = false;

    $result = $queue->process(static function (BeanstalkTestJob $job) use (&$processorCalled): void {
      $processorCalled = true;
    });

    self::assertFalse($processorCalled);
    self::assertSame([['job' => $connection->reservedJob, 'priority' => 512, 'delay' => 15]], $connection->releaseCalls);
    self::assertSame(0, $connection->deleteCalls);
    self::assertTrue($result->isError());
    self::assertInstanceOf(QueueException::class, $result->getNextError());
    self::assertNull($result->getJob());
  }
}

final readonly class BeanstalkTestJob
{
  public function __construct(public string $task)
  {
  }
}

final class TestBeanstalkQueue extends BeanstalkQueue
{
  public function __construct()
  {
  }

  public function prime(
    string $name,
    FakePheanstalkClient $connection,
    ?QueueJobCodecInterface $jobCodec = null,
    int $reserveTimeout = 0,
    int $retryPriority = PheanstalkPublisherInterface::DEFAULT_PRIORITY,
    int $retryDelay = PheanstalkPublisherInterface::DEFAULT_DELAY,
  ): void {
    $this->name = $name;
    $this->connection = $connection;
    $this->tubeName = new TubeName($name);
    $this->logger = new NullLogger();
    $this->jobCodec = $jobCodec ?? new JsonQueueJobCodec();
    $this->reserveTimeout = $reserveTimeout;
    $this->retryPriority = $retryPriority;
    $this->retryDelay = $retryDelay;
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
    ?QueueJobCodecInterface $jobCodec = null,
    int $reserveTimeout = 0,
    int $retryPriority = PheanstalkPublisherInterface::DEFAULT_PRIORITY,
    int $retryDelay = PheanstalkPublisherInterface::DEFAULT_DELAY,
  ) {
    $this->captured = [
      'name' => $name,
      'host' => $host,
      'port' => $port,
      'connectionTimeout' => $connectionTimeout,
      'receiveTimeout' => $receiveTimeout,
      'jobCodec' => $jobCodec,
      'reserveTimeout' => $reserveTimeout,
      'retryPriority' => $retryPriority,
      'retryDelay' => $retryDelay,
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
  public array $releaseCalls = [];
  public array $reserveTimeoutCalls = [];
  public array $ignoredTubes = [];

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
    $this->ignoredTubes[] = $tube->value;

    return 1;
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
    $this->releaseCalls[] = [
      'job' => $job,
      'priority' => $priority,
      'delay' => $delay,
    ];
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
    $this->reserveTimeoutCalls[] = $timeout;

    return $this->reservedJob;
  }

  public function touch(JobIdInterface $job): void
  {
    throw new BadMethodCallException('Not used in this test.');
  }

  public function watch(TubeName $tube): int
  {
    $this->watchedTube = $tube;

    return $tube->value === 'default' ? 1 : 2;
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
