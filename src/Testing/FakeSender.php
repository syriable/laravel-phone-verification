<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Testing;

use PHPUnit\Framework\Assert;
use Syriable\PhoneVerification\Contracts\PhoneVerificationSender;

/**
 * An in-memory sender for tests. Register it as a singleton and point
 * `phone-verification.sender` at it to capture codes instead of
 * delivering them:
 *
 *     $this->app->singleton(FakeSender::class);
 *     config()->set('phone-verification.sender', FakeSender::class);
 */
final class FakeSender implements PhoneVerificationSender
{
    /** @var array<int, array{phone: string, code: string}> */
    private array $sent = [];

    public function send(string $phone, string $code): void
    {
        $this->sent[] = ['phone' => $phone, 'code' => $code];
    }

    /**
     * All codes sent to the given phone number, oldest first.
     *
     * @return list<string>
     */
    public function codesFor(string $phone): array
    {
        return array_column(
            array_filter($this->sent, fn (array $message): bool => $message['phone'] === $phone),
            'code',
        );
    }

    /**
     * The most recently sent code for the given phone number.
     */
    public function lastCodeFor(string $phone): ?string
    {
        $codes = $this->codesFor($phone);

        return $codes === [] ? null : end($codes);
    }

    public function sentCount(?string $phone = null): int
    {
        return $phone === null
            ? count($this->sent)
            : count($this->codesFor($phone));
    }

    public function assertSentTo(string $phone, ?int $times = null): void
    {
        $count = $this->sentCount($phone);

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "Expected a code to be sent to [{$phone}], but none was.");

            return;
        }

        Assert::assertSame($times, $count, "Expected [{$times}] code(s) sent to [{$phone}], but [{$count}] were.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Expected no codes to be sent, but some were.');
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
