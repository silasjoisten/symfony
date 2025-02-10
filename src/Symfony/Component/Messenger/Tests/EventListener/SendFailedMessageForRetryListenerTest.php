<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentForRetryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

class SendFailedMessageForRetryListenerTest extends TestCase
{
    public function testNoRetryStrategyCausesNoRetry()
    {
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->never())->method('send');
        $sendersLocator = new Container();
        $sendersLocator->set('my_receiver', $sender);
        $listener = new SendFailedMessageForRetryListener($sendersLocator, new Container());

        $exception = new \Exception('no!');
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);

        /** @var SentForRetryStamp|null $sentForRetryStamp */
        $sentForRetryStamp = $event->getEnvelope()->last(SentForRetryStamp::class);

        $this->assertInstanceOf(SentForRetryStamp::class, $sentForRetryStamp);
        $this->assertFalse($sentForRetryStamp->isSent);
    }

    public function testIsRetryableFalseCausesNoRetry()
    {
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->never())->method('send');
        $sendersLocator = new Container();
        $sendersLocator->set('my_receiver', $sender);
        $retryStrategyLocator = new Container();
        $retryStrategyLocator->set('my_receiver', new MultiplierRetryStrategy(0));
        $listener = new SendFailedMessageForRetryListener($sendersLocator, $retryStrategyLocator);

        $exception = new \Exception('no!');
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);

        /** @var SentForRetryStamp|null $sentForRetryStamp */
        $sentForRetryStamp = $event->getEnvelope()->last(SentForRetryStamp::class);

        $this->assertInstanceOf(SentForRetryStamp::class, $sentForRetryStamp);
        $this->assertFalse($sentForRetryStamp->isSent);
    }

    public function testRecoverableStrategyCausesRetry()
    {
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->once())->method('send')->willReturnCallback(function (Envelope $envelope) {
            /** @var DelayStamp $delayStamp */
            $delayStamp = $envelope->last(DelayStamp::class);
            /** @var RedeliveryStamp $redeliveryStamp */
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);

            $this->assertInstanceOf(DelayStamp::class, $delayStamp);
            $this->assertSame(1000, $delayStamp->getDelay());

            $this->assertInstanceOf(RedeliveryStamp::class, $redeliveryStamp);
            $this->assertSame(1, $redeliveryStamp->getRetryCount());

            return $envelope;
        });
        $senderLocator = new Container();
        $senderLocator->set('my_receiver', $sender);
        $retryStrategy = $this->createMock(RetryStrategyInterface::class);
        $retryStrategy->expects($this->never())->method('isRetryable');
        $retryStrategy->expects($this->once())->method('getWaitingTime')->willReturn(1000);
        $retryStrategyLocator = new Container();
        $retryStrategyLocator->set('my_receiver', $retryStrategy);

        $listener = new SendFailedMessageForRetryListener($senderLocator, $retryStrategyLocator);

        $exception = new RecoverableMessageHandlingException('retry');
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);

        /** @var SentForRetryStamp|null $sentForRetryStamp */
        $sentForRetryStamp = $event->getEnvelope()->last(SentForRetryStamp::class);

        $this->assertInstanceOf(SentForRetryStamp::class, $sentForRetryStamp);
        $this->assertTrue($sentForRetryStamp->isSent);
    }

    public function testRecoverableExceptionRetryDelayOverridesStrategy()
    {
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->once())->method('send')->willReturnCallback(function (Envelope $envelope) {
            $delayStamp = $envelope->last(DelayStamp::class);
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);

            $this->assertInstanceOf(DelayStamp::class, $delayStamp);
            $this->assertSame(1234, $delayStamp->getDelay());

            $this->assertInstanceOf(RedeliveryStamp::class, $redeliveryStamp);
            $this->assertSame(1, $redeliveryStamp->getRetryCount());

            return $envelope;
        });
        $senderLocator = new Container();
        $senderLocator->set('my_receiver', $sender);
        $retryStrategy = $this->createMock(RetryStrategyInterface::class);
        $retryStrategy->expects($this->never())->method('isRetryable');
        $retryStrategy->expects($this->never())->method('getWaitingTime');
        $retryStrategyLocator = new Container();
        $retryStrategyLocator->set('my_receiver', $retryStrategy);

        $listener = new SendFailedMessageForRetryListener($senderLocator, $retryStrategyLocator);

        $exception = new RecoverableMessageHandlingException('retry', retryDelay: 1234);
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);
    }

    /**
     * @dataProvider provideRetryDelays
     */
    public function testWrappedRecoverableExceptionRetryDelayOverridesStrategy(array $retries, int $expectedDelay)
    {
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->once())->method('send')->willReturnCallback(function (Envelope $envelope) use ($expectedDelay) {
            $delayStamp = $envelope->last(DelayStamp::class);
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);

            $this->assertInstanceOf(DelayStamp::class, $delayStamp);
            $this->assertSame($expectedDelay, $delayStamp->getDelay());

            $this->assertInstanceOf(RedeliveryStamp::class, $redeliveryStamp);
            $this->assertSame(1, $redeliveryStamp->getRetryCount());

            return $envelope;
        });
        $senderLocator = new Container();
        $senderLocator->set('my_receiver', $sender);
        $retryStrategy = $this->createMock(RetryStrategyInterface::class);
        $retryStrategy->expects($this->never())->method('isRetryable');
        $retryStrategy->expects($this->never())->method('getWaitingTime');
        $retryStrategyLocator = new Container();
        $retryStrategyLocator->set('my_receiver', $retryStrategy);

        $listener = new SendFailedMessageForRetryListener($senderLocator, $retryStrategyLocator);

        $envelope = new Envelope(new \stdClass());
        $exception = new HandlerFailedException(
            $envelope,
            array_map(fn (int $retry) => new RecoverableMessageHandlingException('retry', retryDelay: $retry), $retries)
        );
        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);
    }

    public static function provideRetryDelays(): iterable
    {
        yield 'one_exception' => [
            [1235],
            1235,
        ];

        yield 'multiple_exceptions' => [
            [1235, 2000, 1000],
            1000,
        ];

        yield 'zero_delay' => [
            [0, 2000, 1000],
            0,
        ];
    }

    public function testEnvelopeIsSentToTransportOnRetry()
    {
        $exception = new \Exception('no!');
        $envelope = new Envelope(new \stdClass());

        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->once())->method('send')->willReturnCallback(function (Envelope $envelope) {
            /** @var DelayStamp $delayStamp */
            $delayStamp = $envelope->last(DelayStamp::class);
            /** @var RedeliveryStamp $redeliveryStamp */
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);

            $this->assertInstanceOf(DelayStamp::class, $delayStamp);
            $this->assertSame(1000, $delayStamp->getDelay());

            $this->assertInstanceOf(RedeliveryStamp::class, $redeliveryStamp);
            $this->assertSame(1, $redeliveryStamp->getRetryCount());

            return $envelope;
        });
        $senderLocator = new Container();
        $senderLocator->set('my_receiver', $sender);
        $retryStrategy = $this->createMock(RetryStrategyInterface::class);
        $retryStrategy->expects($this->once())->method('isRetryable')->willReturn(true);
        $retryStrategy->expects($this->once())->method('getWaitingTime')->willReturn(1000);
        $retryStrategyLocator = new Container();
        $retryStrategyLocator->set('my_receiver', $retryStrategy);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())->method('dispatch');

        $listener = new SendFailedMessageForRetryListener($senderLocator, $retryStrategyLocator, null, $eventDispatcher);

        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);

        /** @var SentForRetryStamp|null $sentForRetryStamp */
        $sentForRetryStamp = $event->getEnvelope()->last(SentForRetryStamp::class);

        $this->assertInstanceOf(SentForRetryStamp::class, $sentForRetryStamp);
        $this->assertTrue($sentForRetryStamp->isSent);
    }

    public function testEnvelopeIsSentToTransportOnRetryWithExceptionPassedToRetryStrategy()
    {
        $exception = new \Exception('no!');
        $envelope = new Envelope(new \stdClass());

        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->once())->method('send')->willReturnCallback(function (Envelope $envelope) {
            /** @var DelayStamp $delayStamp */
            $delayStamp = $envelope->last(DelayStamp::class);
            /** @var RedeliveryStamp $redeliveryStamp */
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);

            $this->assertInstanceOf(DelayStamp::class, $delayStamp);
            $this->assertSame(1000, $delayStamp->getDelay());

            $this->assertInstanceOf(RedeliveryStamp::class, $redeliveryStamp);
            $this->assertSame(1, $redeliveryStamp->getRetryCount());

            return $envelope;
        });
        $senderLocator = new Container();
        $senderLocator->set('my_receiver', $sender);
        $retryStrategy = $this->createMock(RetryStrategyInterface::class);
        $retryStrategy->expects($this->once())->method('isRetryable')->with($envelope, $exception)->willReturn(true);
        $retryStrategy->expects($this->once())->method('getWaitingTime')->with($envelope, $exception)->willReturn(1000);
        $retryStrategyLocator = new Container();
        $retryStrategyLocator->set('my_receiver', $retryStrategy);

        $listener = new SendFailedMessageForRetryListener($senderLocator, $retryStrategyLocator);

        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);

        /** @var SentForRetryStamp|null $sentForRetryStamp */
        $sentForRetryStamp = $event->getEnvelope()->last(SentForRetryStamp::class);

        $this->assertInstanceOf(SentForRetryStamp::class, $sentForRetryStamp);
        $this->assertTrue($sentForRetryStamp->isSent);
    }

    public function testEnvelopeKeepOnlyTheLast10Stamps()
    {
        $exception = new \Exception('no!');
        $stamps = array_merge(
            array_fill(0, 15, new DelayStamp(1)),
            array_fill(0, 3, new RedeliveryStamp(1))
        );
        $envelope = new Envelope(new \stdClass(), $stamps);

        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->once())->method('send')->willReturnCallback(function (Envelope $envelope) {
            $delayStamps = $envelope->all(DelayStamp::class);
            $redeliveryStamps = $envelope->all(RedeliveryStamp::class);

            $this->assertCount(10, $delayStamps);
            $this->assertCount(4, $redeliveryStamps);

            return $envelope;
        });
        $senderLocator = new Container();
        $senderLocator->set('my_receiver', $sender);
        $retryStrategy = $this->createMock(RetryStrategyInterface::class);
        $retryStrategy->expects($this->once())->method('isRetryable')->willReturn(true);
        $retryStrategy->expects($this->once())->method('getWaitingTime')->willReturn(1000);
        $retryStrategyLocator = new Container();
        $retryStrategyLocator->set('my_receiver', $retryStrategy);

        $listener = new SendFailedMessageForRetryListener($senderLocator, $retryStrategyLocator);

        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);

        /** @var SentForRetryStamp|null $sentForRetryStamp */
        $sentForRetryStamp = $event->getEnvelope()->last(SentForRetryStamp::class);

        $this->assertInstanceOf(SentForRetryStamp::class, $sentForRetryStamp);
        $this->assertTrue($sentForRetryStamp->isSent);
    }

    public function testRetriedEnvelopePassesToRetriedEvent()
    {
        $exception = new \Exception('no!');
        $envelope = new Envelope(new \stdClass());

        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->once())->method('send')->willReturnCallback(static function (Envelope $envelope) {
            return $envelope->with(new TransportMessageIdStamp(123));
        });

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())->method('dispatch')->willReturnCallback(
            function (WorkerMessageRetriedEvent $retriedEvent) {
                $envelope = $retriedEvent->getEnvelope();

                $transportIdStamp = $envelope->last(TransportMessageIdStamp::class);
                $this->assertNotNull($transportIdStamp);

                return $retriedEvent;
            });

        $senderLocator = new Container();
        $senderLocator->set('my_receiver', $sender);

        $retryStrategy = $this->createMock(RetryStrategyInterface::class);
        $retryStrategy->expects($this->once())->method('isRetryable')->willReturn(true);
        $retryStrategy->expects($this->once())->method('getWaitingTime')->willReturn(1000);

        $retryStrategyLocator = new Container();
        $retryStrategyLocator->set('my_receiver', $retryStrategy);

        $listener = new SendFailedMessageForRetryListener(
            $senderLocator,
            $retryStrategyLocator,
            null,
            $eventDispatcher
        );

        $event = new WorkerMessageFailedEvent($envelope, 'my_receiver', $exception);

        $listener->onMessageFailed($event);

        /** @var SentForRetryStamp|null $sentForRetryStamp */
        $sentForRetryStamp = $event->getEnvelope()->last(SentForRetryStamp::class);

        $this->assertInstanceOf(SentForRetryStamp::class, $sentForRetryStamp);
        $this->assertTrue($sentForRetryStamp->isSent);
    }
}
