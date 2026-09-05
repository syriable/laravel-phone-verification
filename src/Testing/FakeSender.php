<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Testing;

use PHPUnit\Framework\Assert;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Contracts\OtpSender;
use Syriable\OtpVerification\Support\OtpMessage;

/**
 * An in-memory sender for tests. Register it as a singleton and point every
 * channel at it to capture codes instead of delivering them:
 *
 *     $this->app->singleton(FakeSender::class);
 *     config()->set('otp-verification.channels.sms.sender', FakeSender::class);
 *     config()->set('otp-verification.channels.mail.sender', FakeSender::class);
 *
 * One instance captures every channel; assertions narrow by channel when you
 * pass one, and span all channels when you don't.
 */
final class FakeSender implements OtpSender
{
    /** @var list<OtpMessage> */
    private array $sent = [];

    public function send(OtpMessage $message): void
    {
        $this->sent[] = $message;
    }

    /**
     * All codes sent to an identifier, oldest first.
     *
     * @return list<string>
     */
    public function codesFor(string $identifier, ?Channel $channel = null): array
    {
        return array_map(
            static fn (OtpMessage $message): string => $message->code,
            $this->messagesFor($identifier, $channel),
        );
    }

    /**
     * The most recently sent code for an identifier.
     */
    public function lastCodeFor(string $identifier, ?Channel $channel = null): ?string
    {
        $codes = $this->codesFor($identifier, $channel);

        return $codes === [] ? null : $codes[count($codes) - 1];
    }

    /**
     * Every message captured so far, oldest first.
     *
     * @return list<OtpMessage>
     */
    public function sent(?Channel $channel = null): array
    {
        if (! $channel instanceof Channel) {
            return $this->sent;
        }

        return array_values(array_filter(
            $this->sent,
            static fn (OtpMessage $message): bool => $message->channel()->is($channel),
        ));
    }

    public function sentCount(?string $identifier = null, ?Channel $channel = null): int
    {
        return $identifier === null
            ? count($this->sent($channel))
            : count($this->messagesFor($identifier, $channel));
    }

    /**
     * @param  Channel|int|null  $channel  passing an int is the deprecated v1
     *                                     positional form, assertSentTo($identifier, $times)
     */
    public function assertSentTo(string $identifier, Channel|int|null $channel = null, ?int $times = null): void
    {
        if (is_int($channel)) {
            $times = $channel;
            $channel = null;
        }

        $count = $this->sentCount($identifier, $channel);
        $where = $channel instanceof Channel ? " on the [{$channel->value}] channel" : '';

        if ($times === null) {
            Assert::assertGreaterThan(
                0,
                $count,
                "Expected a code to be sent to [{$identifier}]{$where}, but none was."
            );

            return;
        }

        Assert::assertSame(
            $times,
            $count,
            "Expected [{$times}] code(s) sent to [{$identifier}]{$where}, but [{$count}] were."
        );
    }

    public function assertSentOn(Channel $channel, ?int $times = null): void
    {
        $count = count($this->sent($channel));

        if ($times === null) {
            Assert::assertGreaterThan(
                0,
                $count,
                "Expected a code to be sent on the [{$channel->value}] channel, but none was."
            );

            return;
        }

        Assert::assertSame(
            $times,
            $count,
            "Expected [{$times}] code(s) sent on the [{$channel->value}] channel, but [{$count}] were."
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertCount(0, $this->sent, 'Expected no codes to be sent, but some were.');
    }

    public function assertNothingSentOn(Channel $channel): void
    {
        Assert::assertCount(
            0,
            $this->sent($channel),
            "Expected no codes to be sent on the [{$channel->value}] channel, but some were."
        );
    }

    public function reset(): void
    {
        $this->sent = [];
    }

    /**
     * @return list<OtpMessage>
     */
    private function messagesFor(string $identifier, ?Channel $channel): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn (OtpMessage $message): bool => $message->identifier() === $identifier
                && (! $channel instanceof Channel || $message->channel()->is($channel)),
        ));
    }
}
