<?php

namespace Assegaiphp\Beanstalkd;

use Assegai\Common\Interfaces\Queues\QueueProcessResultInterface;
use Throwable;

/**
 * Class BeanstalkQueueProcessResult
 *
 * Represents the result of processing a job in a Beanstalk queue.
 * Implements the QueueProcessResultInterface.
 * @template T
 */
class BeanstalkQueueProcessResult implements QueueProcessResultInterface
{
  public function __construct(
    protected mixed $data = null,
    protected array $errors = [],
    protected ?object $job = null
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function getData(): mixed
  {
    return $this->data;
  }

  /**
   * @inheritDoc
   */
  public function isOk(): bool
  {
    return !$this->isError();
  }

  /**
   * @inheritDoc
   */
  public function isError(): bool
  {
    return !empty($this->errors);
  }

  /**
   * @inheritDoc
   */
  public function getErrors(): array
  {
    return $this->errors;
  }

  /**
   * @inheritDoc
   */
  public function getNextError(): ?Throwable
  {
    return $this->errors[0] ?? null;
  }

  /**
   * @inheritDoc
   */
  public function getJob(): ?object
  {
    return $this->job;
  }
}