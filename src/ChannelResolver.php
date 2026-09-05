<?php

declare(strict_types=1);

namespace Syriable\OtpVerification;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\Container;
use Syriable\OtpVerification\Contracts\OtpGenerator;
use Syriable\OtpVerification\Contracts\OtpSender;
use Syriable\OtpVerification\Contracts\SendRateLimiter;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Generators\RandomOtpGenerator;
use Syriable\OtpVerification\RateLimiting\CacheSendRateLimiter;
use Syriable\OtpVerification\Senders\QueuedOtpSender;
use Syriable\OtpVerification\Support\ChannelConfig;
use Syriable\OtpVerification\Support\OtpVerificationConfig;
use Syriable\OtpVerification\Support\QueueConfig;

/**
 * Given a channel, produce the collaborators that channel is configured with.
 *
 * A plain container binding cannot do this: the sender, the code shape and
 * the send window all vary per channel, so they cannot be resolved once at
 * registration time. Everything this class produces is itself swappable
 * through configuration, which is why it has no interface of its own.
 */
final readonly class ChannelResolver
{
    public function __construct(
        private Container $container,
        private OtpVerificationConfig $config,
        private BusDispatcher $bus,
    ) {}

    /**
     * Every channel registered in the configuration file.
     *
     * @return list<Channel>
     */
    public function channels(): array
    {
        return $this->config->channels();
    }

    public function config(Channel $channel): ChannelConfig
    {
        $this->assertRegistered($channel);

        return $this->config->forChannel($channel);
    }

    /**
     * The sender for a channel, wrapped for queued delivery when the channel
     * asks for it.
     */
    public function sender(Channel $channel): OtpSender
    {
        $config = $this->config($channel);

        $sender = $this->assertContract(
            "channels.{$channel->value}.sender",
            $config->sender,
            OtpSender::class,
        );

        // Queued delivery resolves the sender on the worker, so the sender is
        // never constructed (nor its provider client opened) in the request.
        if ($config->queue instanceof QueueConfig) {
            return new QueuedOtpSender($this->bus, $sender, $config->queue);
        }

        /** @var OtpSender */
        return $this->container->make($sender);
    }

    public function generator(Channel $channel): OtpGenerator
    {
        $config = $this->config($channel);

        if ($config->generator !== null) {
            return $this->make(
                "channels.{$channel->value}.otp.generator",
                $config->generator,
                OtpGenerator::class,
            );
        }

        return new RandomOtpGenerator($config->otpLength, $config->otpCharacters);
    }

    public function rateLimiter(Channel $channel): SendRateLimiter
    {
        $this->assertRegistered($channel);

        return $this->make(
            'rate_limiter',
            $this->config->rateLimiter() ?? CacheSendRateLimiter::class,
            SendRateLimiter::class,
        );
    }

    private function assertRegistered(Channel $channel): void
    {
        if ($this->config->hasChannel($channel)) {
            return;
        }

        throw InvalidConfiguration::unknownChannel(
            $channel->value,
            array_map(static fn (Channel $registered): string => $registered->value, $this->channels()),
        );
    }

    /**
     * Resolve a configured class from the container after asserting it
     * satisfies the contract, so a misconfiguration fails with a message
     * naming the key, the class and the interface.
     *
     * @template TContract of object
     *
     * @param  class-string  $class
     * @param  class-string<TContract>  $contract
     * @return TContract
     */
    private function make(string $key, string $class, string $contract): object
    {
        /** @var TContract */
        return $this->container->make($this->assertContract($key, $class, $contract));
    }

    /**
     * Assert a configured class satisfies its contract, without building it.
     *
     * @template TAsserted of object
     *
     * @param  class-string  $class
     * @param  class-string<TAsserted>  $contract
     * @return class-string<TAsserted>
     */
    private function assertContract(string $key, string $class, string $contract): string
    {
        if (! is_a($class, $contract, true)) {
            throw InvalidConfiguration::invalidImplementation($key, $class, $contract);
        }

        return $class;
    }
}
