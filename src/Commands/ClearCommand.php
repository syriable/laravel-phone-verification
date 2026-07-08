<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Commands;

use Illuminate\Console\Command;
use Syriable\PhoneVerification\Contracts\VerificationRepository;

class ClearCommand extends Command
{
    protected $signature = 'verification:clear {phone? : Only clear records for this phone number}';

    protected $description = 'Delete phone verification records, optionally for a single phone number';

    public function handle(VerificationRepository $repository): int
    {
        $phone = $this->argument('phone');
        $phone = is_string($phone) && $phone !== '' ? $phone : null;

        $deleted = $repository->clear($phone);

        $this->info($phone === null
            ? "Removed all {$deleted} phone verification record(s)."
            : "Removed {$deleted} phone verification record(s) for {$phone}.");

        return self::SUCCESS;
    }
}
