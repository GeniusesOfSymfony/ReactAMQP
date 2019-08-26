<?php declare(strict_types=1);

namespace Gos\Component\ReactAMQP\Tests;

use Gos\Component\ReactAMQP\Producer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * @author Jeremy Cook <jeremycook0@gmail.com>
 *
 * @requires extension amqp
 */
final class ProducerTest extends TestCase
{
    /**
     * @var \AMQPExchange|MockObject
     */
    private $exchange;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * Counter to keep track of the number of times this object is called as a callback.
     *
     * @var int
     */
    private $counter = 0;

    /**
     * @var TimerInterface
     */
    private $timer;

    protected function setUp(): void
    {
        $this->exchange = $this->createMock(\AMQPExchange::class);
        $this->loop = $this->createMock(LoopInterface::class);
        $this->timer = $this->createMock(TimerInterface::class);

        $this->loop->expects($this->any())
            ->method('addPeriodicTimer')
            ->willReturn($this->timer);
    }

    protected function tearDown(): void
    {
        $this->counter = 0;
    }

    /**
     * Allows the test class to be used as a callback by the producer.
     *
     * Simply counts the number of times the invoke method is called.
     */
    public function __invoke(): void
    {
        ++$this->counter;
    }

    /**
     * @dataProvider intervalSupplier
     */
    public function testProducerIsInstantiatedAndRegisteredToTheLoop(float $interval): void
    {
        $this->loop->expects($this->once())
            ->method('addPeriodicTimer')
            ->with($this->identicalTo($interval), $this->isInstanceOf(Producer::class));

        new Producer($this->exchange, $this->loop, $interval);
    }

    /**
     * Tests the publish method of the producer.
     *
     * @dataProvider messageProvider
     */
    public function testPublish(string $message, string $routingKey, int $flags, array $attributes): void
    {
        $producer = new Producer($this->exchange, $this->loop, 1);

        $this->assertCount(0, $producer);

        $producer->publish($message, $routingKey, $flags, $attributes);

        $this->assertCount(1, $producer);
    }

    /**
     * Asserts that messages stored in the object can be sent.
     *
     * @depends      testPublish
     * @dataProvider messagesProvider
     */
    public function testSendingMessages(array $messages): void
    {
        $producer = new Producer($this->exchange, $this->loop, 1);
        $producer->on('produce', $this);

        foreach ($messages as $message) {
            \call_user_func_array([$producer, 'publish'], $message);
        }

        $this->exchange->expects($this->exactly(\count($messages)))
            ->method('publish');

        $this->assertCount(\count($messages), $producer);

        $producer();

        $this->assertSame(\count($messages), $this->counter);
        $this->assertCount(0, $producer);
    }

    /**
     * Tests the behaviour of the producer when an exception is raised by the exchange.
     *
     * @depends      testPublish
     * @dataProvider messagesProvider
     */
    public function testSendingMessagesWithError(array $messages): void
    {
        $producer = new Producer($this->exchange, $this->loop, 1);
        $producer->on('error', $this);

        foreach ($messages as $message) {
            \call_user_func_array([$producer, 'publish'], $message);
        }

        $this->exchange->expects($this->exactly(\count($messages)))
            ->method('publish')
            ->will($this->throwException(new \AMQPExchangeException()));

        $producer();

        $this->assertSame(\count($messages), $this->counter);
        $this->assertCount(\count($messages), $producer);
    }

    /**
     * @dataProvider callProvider
     */
    public function testTheProducerProxiesMethodCalls($method, $arg): void
    {
        $this->exchange->expects($this->once())
            ->method($method)
            ->with($arg);

        $producer = new Producer($this->exchange, $this->loop, 1);
        $producer->$method($arg);
    }

    public function testClose(): void
    {
        $producer = new Producer($this->exchange, $this->loop, 1);
        $producer->on('end', $this);

        $this->loop->expects($this->once())
            ->method('cancelTimer')
            ->with($this->timer);

        $producer->close();

        $this->assertTrue($producer->isClosed());
        $this->assertSame(1, $this->counter);
    }

    /**
     * @depends testPublish
     * @dataProvider messagesProvider
     */
    public function testCount(array $messages): void
    {
        $producer = new Producer($this->exchange, $this->loop, 1);
        $this->assertCount(0, $producer);

        foreach ($messages as $message) {
            \call_user_func_array([$producer, 'publish'], $message);
        }

        $this->assertSame(\count($messages), \count($producer));
    }

    /**
     * @depends testPublish
     * @dataProvider messagesProvider
     */
    public function testGetIterator(array $messages): void
    {
        $producer = new Producer($this->exchange, $this->loop, 1);
        $ret = $producer->getIterator();

        $this->assertTrue(\is_array($ret));
        $this->assertEmpty($ret);

        foreach ($messages as $message) {
            \call_user_func_array([$producer, 'publish'], $message);
        }

        $ret = $producer->getIterator();
        $this->assertTrue(\is_array($ret));
        $this->assertNotEmpty($ret);
    }

    /**
     * @depends testClose
     */
    public function testPublishingAfterClosingIsNotAllowed(): void
    {
        $this->expectException(\BadMethodCallException::class);

        $producer = new Producer($this->exchange, $this->loop, 1);
        $producer->close();
        $producer->publish('foo', 'bar');
    }

    /**
     * @depends testClose
     */
    public function testInvokingProducerAfterClosingIsNotAllowed(): void
    {
        $this->expectException(\BadMethodCallException::class);

        $producer = new Producer($this->exchange, $this->loop, 1);
        $producer->close();
        $producer();
    }

    public function intervalSupplier(): \Generator
    {
        yield [1];
        yield [2.4];
        yield [0.05];
    }

    public function messageProvider(): \Generator
    {
        yield ['foo', 'bar', 1 & 1, []];
        yield ['bar', 'baz', 1 & 0, ['foo' => 'bar']];
    }

    public function messagesProvider(): \Generator
    {
        yield [
            [
                ['foo', 'bar', 1 & 1, []],
                ['bar', 'baz', 1 & 0, ['foo' => 'bar']],
            ]
        ];
    }

    public function callProvider(): \Generator
    {
        yield ['setName', 'foo'];
        yield ['setType', 'bar'];
    }
}
