<?php

declare(strict_types=1);

namespace Syriable\OtpVerification;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Syriable\OtpVerification\Commands\CleanupCommand;
use Syriable\OtpVerification\Commands\ClearCommand;
use Syriable\OtpVerification\Commands\MigrateV1Command;
use Syriable\OtpVerification\Contracts\CodeHasher;
use Syriable\OtpVerification\Contracts\LinkRepository;
use Syriable\OtpVerification\Contracts\SendRateLimiter;
use Syriable\OtpVerification\Contracts\VerificationRepository;
use Syriable\OtpVerification\Events\VerificationSucceeded;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Hashing\HmacCodeHasher;
use Syriable\OtpVerification\Listeners\MarkEmailAsVerified;
use Syriable\OtpVerification\RateLimiting\CacheSendRateLimiter;
use Syriable\OtpVerification\Repositories\DatabaseLinkRepository;
use Syriable\OtpVerification\Repositories\DatabaseVerificationRepository;
use Syriable\OtpVerification\Support\OtpVerificationConfig;

final class OtpVerificationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-otp-verification')
            ->hasConfigFile('otp-verification')
            ->hasMigrations([
                'create_verifications_table',
                'create_verification_links_table',
            ])
            ->hasCommands(CleanupCommand::class, ClearCommand::class, MigrateV1Command::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(
            OtpVerificationConfig::class,
            static fn (Container $app): OtpVerificationConfig => new OtpVerificationConfig(
                $app->make(ConfigRepository::class),
            ),
        );

        $this->app->bind(
            ChannelResolver::class,
            static fn (Container $app): ChannelResolver => new ChannelResolver(
                container: $app,
                config: $app->make(OtpVerificationConfig::class),
                bus: $app->make(BusDispatcher::class),
            ),
        );

        $this->app->bind(CodeHasher::class, function (Container $app): CodeHasher {
            $driver = $app->make(OtpVerificationConfig::class)->hashDriver() ?? HmacCodeHasher::class;

            return $this->makeConfigured($app, 'hash_driver', $driver, CodeHasher::class);
        });

        $this->app->bind(
            HmacCodeHasher::class,
            fn (Container $app): HmacCodeHasher => new HmacCodeHasher($this->applicationKey($app)),
        );

        $this->app->bind(VerificationRepository::class, function (Container $app): VerificationRepository {
            $repository = $app->make(OtpVerificationConfig::class)->repository()
                ?? DatabaseVerificationRepository::class;

            return $this->makeConfigured($app, 'repository', $repository, VerificationRepository::class);
        });

        $this->app->bind(LinkRepository::class, function (Container $app): LinkRepository {
            $repository = $app->make(OtpVerificationConfig::class)->linkRepository()
                ?? DatabaseLinkRepository::class;

            return $this->makeConfigured($app, 'link_repository', $repository, LinkRepository::class);
        });

        $this->app->bind(SendRateLimiter::class, function (Container $app): SendRateLimiter {
            $limiter = $app->make(OtpVerificationConfig::class)->rateLimiter() ?? CacheSendRateLimiter::class;

            return $this->makeConfigured($app, 'rate_limiter', $limiter, SendRateLimiter::class);
        });

        $this->app->bind(
            CacheSendRateLimiter::class,
            static fn (Container $app): CacheSendRateLimiter => new CacheSendRateLimiter(
                $app->make(RateLimiter::class),
            ),
        );

        $this->app->bind(
            VerificationManager::class,
            static fn (Container $app): VerificationManager => new VerificationManager(
                channels: $app->make(ChannelResolver::class),
                repository: $app->make(VerificationRepository::class),
                linkRepository: $app->make(LinkRepository::class),
                hasher: $app->make(CodeHasher::class),
                config: $app->make(OtpVerificationConfig::class),
                events: $app->make(EventDispatcher::class),
            ),
        );
    }

    public function packageBooted(): void
    {
        // Registered only when enabled, so the bridge is genuinely inert
        // rather than a listener that returns early.
        if (! $this->app->make(OtpVerificationConfig::class)->marksEmailAsVerified()) {
            return;
        }

        $this->app->make(EventDispatcher::class)->listen(
            VerificationSucceeded::class,
            MarkEmailAsVerified::class,
        );
    }

    /**
     * Resolve a configured class after asserting it implements the contract.
     *
     * @template TInterface of object
     *
     * @param  class-string  $class
     * @param  class-string<TInterface>  $interface
     * @return TInterface
     */
    private function makeConfigured(Container $app, string $key, string $class, string $interface): object
    {
        if (! is_a($class, $interface, true)) {
            throw InvalidConfiguration::invalidImplementation($key, $class, $interface);
        }

        /** @var TInterface */
        return $app->make($class);
    }

    private function applicationKey(Container $app): string
    {
        $key = $app->make(ConfigRepository::class)->get('app.key');
        $key = is_string($key) ? $key : '';

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? '' : $decoded;
        }

        return $key;
    }
}
