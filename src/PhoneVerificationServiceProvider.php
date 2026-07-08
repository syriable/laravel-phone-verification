<?php

namespace Syriable\PhoneVerification;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Syriable\PhoneVerification\Commands\PhoneVerificationCommand;

class PhoneVerificationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-phone-verification')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_phone_verification_table')
            ->hasCommand(PhoneVerificationCommand::class);
    }
}
