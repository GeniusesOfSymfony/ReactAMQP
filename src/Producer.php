<?php declare(strict_types=1);

namespace Gos\Component\ReactAMQP;

use AMQPExchange;
use AMQPExchangeException;
use BadMethodCallException;
use Countable;
use Evenement\EventEmitter;
use IteratorAggregate;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Class to publish messages to an AMQP exchange.
 *
 * @author  Jeremy Cook <jeremycook0@gmail.com>
 */
class Producer extends EventEmitter implements Countable, IteratorAggregate
{
    /**
     * AMQP message exchange to send messages to.
     *
     * @var AMQPExchange
     */
    protected $exchange;

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
     * Collection of messages waiting to be sent.
     *
     * @var array
     */
    protected $messages = [];

    /**
     * @var TimerInterface
     */
    protected $timer;

    /**
     * Constructor. Stores the message queue and the event loop for use.
     *
     * @param AMQPExchange  $exchange Message queue
     * @param LoopInterface $loop     Event loop
     * @param float         $interval Interval to run loop to send messages
     */
    public function __construct(AMQPExchange $exchange, LoopInterface $loop, $interval)
    {
        $this->exchange = $exchange;
        $this->loop = $loop;
        $this->timer = $this->loop->addPeriodicTimer($interval, $this);
    }

    /**
     * Returns the number of messages waiting to be sent. Implements the
     * countable interface.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Returns the array of messages stored. Completes the implementation of
     * the iteratorAggregate interface.
     *
     * @return array
     */
    public function getIterator(): array
    {
        return $this->messages;
    }

    /**
     * Method to publish a message to an AMQP exchange. Has the same method
     * signature as the exchange objects publish method.
     *
     * @param string   $message    Message
     * @param string   $routingKey Routing key
     * @param int|null $flags      Flags
     * @param array    $attributes Attributes
     *
     * @throws BadMethodCallException
     */
    public function publish(string $message, string $routingKey, ?int $flags = null, $attributes = []): void
    {
        if ($this->closed) {
            throw new BadMethodCallException('This Producer object is closed and cannot send any more messages.');
        }
        $this->messages[] = [
            'message' => $message,
            'routingKey' => $routingKey,
            'flags' => $flags,
            'attributes' => $attributes,
        ];
    }

    /**
     * Callback to dispatch on the loop timer.
     *
     * @throws \AMQPChannelException|\AMQPConnectionException
     */
    public function __invoke(): void
    {
        if ($this->closed) {
            throw new BadMethodCallException('This Producer object is closed and cannot send any more messages.');
        }
        foreach ($this->messages as $key => $message) {
            try {
                $this->exchange->publish($message['message'], $message['routingKey'], $message['flags'], $message['attributes']);
                unset($this->messages[$key]);
                $this->emit('produce', array_values($message));
            } catch (AMQPExchangeException $e) {
                $this->emit('error', [$e]);
            }
        }
    }

    /**
     * Allows calls to unknown methods to be passed through to the exchange
     * stored.
     *
     * @param string $method Method name
     * @param mixed  $args   Args to pass
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->exchange, $method], $args);
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
        $this->exchange = null;
        $this->closed = true;
    }
}
