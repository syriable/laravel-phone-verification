<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Syriable\PhoneVerification\Commands\CleanupCommand;
use Syriable\PhoneVerification\Commands\ClearCommand;
use Syriable\PhoneVerification\Contracts\CodeHasher;
use Syriable\PhoneVerification\Contracts\OtpGenerator;
use Syriable\PhoneVerification\Contracts\PhoneVerificationSender;
use Syriable\PhoneVerification\Contracts\SendRateLimiter;
use Syriable\PhoneVerification\Contracts\VerificationRepository;
use Syriable\PhoneVerification\Exceptions\InvalidConfiguration;
use Syriable\PhoneVerification\Generators\RandomOtpGenerator;
use Syriable\PhoneVerification\Hashing\HmacCodeHasher;
use Syriable\PhoneVerification\RateLimiting\CacheSendRateLimiter;
use Syriable\PhoneVerification\Repositories\DatabaseVerificationRepository;
use Syriable\PhoneVerification\Support\PhoneVerificationConfig;

class PhoneVerificationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-phone-verification')
            ->hasConfigFile()
            ->hasMigration('create_phone_verifications_table')
            ->hasCommands(CleanupCommand::class, ClearCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(OtpGenerator::class, function (Container $app): OtpGenerator {
            $config = $app->make(PhoneVerificationConfig::class);

            $custom = $config->customGenerator();

            if ($custom !== null) {
                return $this->makeConfigured($app, 'otp.generator', $custom, OtpGenerator::class);
            }

            return new RandomOtpGenerator($config->otpLength(), $config->otpCharacters());
        });

        $this->app->bind(PhoneVerificationSender::class, function (Container $app): PhoneVerificationSender {
            $sender = $app->make(PhoneVerificationConfig::class)->sender()
                ?? throw InvalidConfiguration::missingSender();

            return $this->makeConfigured($app, 'sender', $sender, PhoneVerificationSender::class);
        });

        $this->app->bind(CodeHasher::class, function (Container $app): CodeHasher {
            $driver = $app->make(PhoneVerificationConfig::class)->hashDriver() ?? HmacCodeHasher::class;

            return $this->makeConfigured($app, 'hash_driver', $driver, CodeHasher::class);
        });

        $this->app->bind(HmacCodeHasher::class, function (Container $app): HmacCodeHasher {
            return new HmacCodeHasher($this->applicationKey($app));
        });

        $this->app->bind(VerificationRepository::class, function (Container $app): VerificationRepository {
            $repository = $app->make(PhoneVerificationConfig::class)->repository()
                ?? DatabaseVerificationRepository::class;

            return $this->makeConfigured($app, 'repository', $repository, VerificationRepository::class);
        });

        $this->app->bind(SendRateLimiter::class, function (Container $app): SendRateLimiter {
            $limiter = $app->make(PhoneVerificationConfig::class)->rateLimiter() ?? CacheSendRateLimiter::class;

            return $this->makeConfigured($app, 'rate_limiter', $limiter, SendRateLimiter::class);
        });

        $this->app->bind(CacheSendRateLimiter::class, function (Container $app): CacheSendRateLimiter {
            $config = $app->make(PhoneVerificationConfig::class);

            return new CacheSendRateLimiter(
                limiter: $app->make(RateLimiter::class),
                maxSends: $config->maxSendAttempts(),
                decaySeconds: $config->perMinutes() * 60,
            );
        });
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
    protected function makeConfigured(Container $app, string $key, string $class, string $interface): object
    {
        if (! is_subclass_of($class, $interface) && $class !== $interface) {
            throw InvalidConfiguration::invalidImplementation($key, $class, $interface);
        }

        /** @var TInterface */
        return $app->make($class);
    }

    protected function applicationKey(Container $app): string
    {
        $key = $app->make(ConfigRepository::class)->get('app.key');
        $key = is_string($key) ? $key : '';

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
            $key = $key === false ? '' : $key;
        }

        return $key;
    }
}
