<?php

namespace Assegai\Beanstalkd;

use Assegai\Common\Queues\QueueProcessResult;

/**
 * Class BeanstalkQueueProcessResult
 *
 * Represents the result of processing a job in a Beanstalk queue.
 * Implements the QueueProcessResultInterface.
 * @template T of object
 * @extends QueueProcessResult<T>
 */
class BeanstalkQueueProcessResult extends QueueProcessResult
{
}
