<?php

namespace Syriable\PhoneVerification\Commands;

use Illuminate\Console\Command;

class PhoneVerificationCommand extends Command
{
    public $signature = 'laravel-phone-verification';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
