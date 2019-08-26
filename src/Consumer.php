<?php declare(strict_types=1);

namespace Gos\Component\ReactAMQP;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Class to listen to an AMQP queue and dispatch listeners when messages are received.
 *
 * @author Jeremy Cook <jeremycook0@gmail.com>
 */
final class Consumer extends EventEmitter
{
    /**
     * @var \AMQPQueue
     */
    private $queue;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var bool
     */
    private $closed = false;

    /**
     * Max number of messages to consume in a 'batch'.
     *
     * Should stop the event loop stopping on this class for protracted lengths of time.
     *
     * @var int
     */
    private $max;

    /**
     * @var TimerInterface
     */
    private $timer;

    public function __construct(\AMQPQueue $queue, LoopInterface $loop, float $interval, ?int $max = null)
    {
        $this->queue = $queue;
        $this->loop = $loop;
        $this->max = $max;
        $this->timer = $this->loop->addPeriodicTimer($interval, $this);

        $this->on('close_amqp_consumer', [$this, 'close']);
    }

    /**
     * Handles receiving an incoming message.
     *
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \BadMethodCallException  if the consumer connection has been closed
     */
    public function __invoke(): void
    {
        if ($this->closed) {
            throw new \BadMethodCallException('This consumer object is closed and cannot receive any more messages.');
        }

        $counter = 0;

        while ($envelope = $this->queue->get()) {
            $this->emit('consume', [$envelope, $this->queue]);

            if ($this->max && ++$counter >= $this->max) {
                return;
            }
        }
    }

    /**
     * Allows calls to unknown methods to be passed through to the queue store.
     *
     * @return mixed
     */
    public function __call(string $method, $args)
    {
        return \call_user_func_array([$this->queue, $method], $args);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->emit('end', [$this]);
        $this->loop->cancelTimer($this->timer);
        $this->removeAllListeners();
        $this->queue = null;
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return true === $this->closed;
    }
}
