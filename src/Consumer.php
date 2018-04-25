<?php declare(strict_types=1);

namespace Gos\Component\ReactAMQP;

use AMQPQueue;
use BadMethodCallException;
use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Class to listen to an AMQP queue and dispatch listeners when messages are
 * received.
 *
 * @author  Jeremy Cook <jeremycook0@gmail.com>
 */
class Consumer extends EventEmitter
{
    /**
     * AMQP message queue to read messages from.
     *
     * @var AMQPQueue
     */
    protected $queue;

    /**
     * Event loop.
     *
     * @var LoopInterface
     */
    protected $loop;

    /**
     * Flag to indicate if this listener is closed.
     *
     * @var bool
     */
    protected $closed = false;

    /**
     * Max number of messages to consume in a 'batch'. Should stop the event
     * loop stopping on this class for protracted lengths of time.
     *
     * @var int
     */
    protected $max;

    /**
     * @var TimerInterface
     */
    private $timer;

    /**
     * Constructor. Stores the message queue and the event loop for use.
     *
     * @param AMQPQueue     $queue    Message queue
     * @param LoopInterface $loop     Event loop
     * @param float|null    $interval Interval to check for new messages
     * @param int|null      $max      Max number of messages to consume in one go
     */
    public function __construct(AMQPQueue $queue, LoopInterface $loop, ?float $interval, ?int $max = null)
    {
        $this->queue = $queue;
        $this->loop = $loop;
        $this->max = $max;
        $this->timer = $this->loop->addPeriodicTimer($interval, $this);

        $this->on('close_amqp_consumer', [$this, 'close']);
    }

    /**
     * Method to handle receiving an incoming message.
     *
     * @throws \AMQPChannelException|\AMQPConnectionException
     */
    public function __invoke(): void
    {
        if ($this->closed) {
            throw new BadMethodCallException('This consumer object is closed and cannot receive any more messages.');
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
     * Allows calls to unknown methods to be passed through to the queue
     * stored.
     *
     * @param string $method Method name
     * @param mixed  $args   Args to pass
     *
     * @return mixed
     */
    public function __call(string $method, $args)
    {
        return call_user_func_array([$this->queue, $method], $args);
    }

    /**
     * Method to call when stopping listening to messages.
     */
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
}
