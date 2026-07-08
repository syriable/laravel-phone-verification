<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Syriable\PhoneVerification\Contracts\VerificationRepository;
use Syriable\PhoneVerification\Support\PhoneVerificationConfig;

class CleanupCommand extends Command
{
    protected $signature = 'verification:cleanup';

    protected $description = 'Remove expired verification codes and stale verified records';

    public function handle(VerificationRepository $repository, PhoneVerificationConfig $config): int
    {
        $now = CarbonImmutable::now();

        $deleted = $repository->prune(
            now: $now,
            verifiedBefore: $now->subDays($config->keepVerifiedForDays()),
        );

        $this->info("Removed {$deleted} stale phone verification record(s).");

        return self::SUCCESS;
    }
}
