<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\ChannelResolver;
use Syriable\OtpVerification\Contracts\VerificationRepository;

final class CleanupCommand extends Command
{
    protected $signature = 'verification:cleanup
        {--channel= : Only clean up this channel}';

    protected $description = 'Remove expired verification codes and stale verified records';

    public function handle(VerificationRepository $repository, ChannelResolver $channels): int
    {
        $targets = $this->targetChannels($channels);

        if ($targets === null) {
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $deleted = 0;

        // Retention is per channel — email records are typically kept longer
        // than SMS ones — so each channel is pruned at its own cutoff.
        foreach ($targets as $channel) {
            $deleted += $repository->prune(
                now: $now,
                verifiedBefore: $now->subDays($channels->config($channel)->keepVerifiedForDays),
                channel: $channel,
            );
        }

        $this->info("Removed {$deleted} stale verification record(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<Channel>|null null when the --channel option is unusable
     */
    private function targetChannels(ChannelResolver $channels): ?array
    {
        $option = $this->option('channel');

        if (! is_string($option) || $option === '') {
            return $channels->channels();
        }

        $channel = Channel::tryOf($option);

        if (! $channel instanceof Channel) {
            $this->error("`{$option}` is not a valid channel name.");

            return null;
        }

        $registered = array_map(static fn (Channel $c): string => $c->value, $channels->channels());

        if (! in_array($channel->value, $registered, true)) {
            $this->error("The channel `{$option}` is not configured. Registered channels: ".implode(', ', $registered).'.');

            return null;
        }

        return [$channel];
    }
}
