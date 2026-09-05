<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Commands;

use Illuminate\Console\Command;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\ChannelResolver;
use Syriable\OtpVerification\Contracts\VerificationRepository;
use Syriable\OtpVerification\Support\OtpVerificationConfig;
use Syriable\OtpVerification\Support\VerificationSubject;

final class ClearCommand extends Command
{
    protected $signature = 'verification:clear
        {identifier? : Only clear records for this identifier}
        {--channel= : Only clear records on this channel}
        {--purpose= : Only clear records for this purpose (with an identifier)}';

    protected $description = 'Delete verification records, optionally for one identifier or one channel';

    public function handle(
        VerificationRepository $repository,
        ChannelResolver $channels,
        OtpVerificationConfig $config,
    ): int {
        $identifier = $this->argument('identifier');
        $identifier = is_string($identifier) && $identifier !== '' ? $identifier : null;

        $option = $this->option('channel');
        $channel = null;

        if (is_string($option) && $option !== '') {
            $channel = Channel::tryOf($option);

            if (! $channel instanceof Channel) {
                $this->error("`{$option}` is not a valid channel name.");

                return self::FAILURE;
            }
        }

        if ($identifier === null) {
            $deleted = $repository->clear(channel: $channel);

            $this->info($channel instanceof Channel
                ? "Removed {$deleted} verification record(s) on the {$channel->value} channel."
                : "Removed all {$deleted} verification record(s).");

            return self::SUCCESS;
        }

        // Clearing one identifier needs a channel: the same address can hold
        // independent records on several channels.
        $channel ??= $config->defaultChannel();

        if (! $channel instanceof Channel) {
            $this->error(
                'Clearing a single identifier needs a channel. Pass --channel, '
                .'or set `otp-verification.default_channel`.'
            );

            return self::FAILURE;
        }

        $purpose = $this->option('purpose');
        $purpose = is_string($purpose) && $purpose !== '' ? $purpose : null;

        $deleted = $repository->clear(VerificationSubject::of($identifier, $channel, $purpose));

        $this->info(sprintf(
            'Removed %d verification record(s) for %s on the %s channel for the %s purpose.',
            $deleted,
            $identifier,
            $channel->value,
            $purpose ?? VerificationSubject::DEFAULT_PURPOSE,
        ));

        return self::SUCCESS;
    }
}
