<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Contracts;

use Carbon\CarbonImmutable;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Support\VerificationRecord;
use Syriable\OtpVerification\Support\VerificationSubject;

interface VerificationRepository
{
    /**
     * Persist a new verification record.
     */
    public function create(
        VerificationSubject $subject,
        string $codeHash,
        CarbonImmutable $expiresAt,
        int $resendCount = 0,
    ): VerificationRecord;

    /**
     * The most recent unverified record for the subject, if any.
     *
     * Implementations must guarantee at most one unverified record exists per
     * subject; invalidate() runs before every create().
     */
    public function findActive(VerificationSubject $subject): ?VerificationRecord;

    /**
     * The most recent successfully verified record for the subject.
     */
    public function findVerified(VerificationSubject $subject): ?VerificationRecord;

    /**
     * When a code was last sent to the subject, verified or not.
     */
    public function lastSentAt(VerificationSubject $subject): ?CarbonImmutable;

    /**
     * Atomically increment the attempt counter and return the fresh record.
     */
    public function incrementAttempts(VerificationRecord $record): VerificationRecord;

    /**
     * Mark the record as successfully verified at the given moment.
     */
    public function markVerified(VerificationRecord $record, CarbonImmutable $verifiedAt): VerificationRecord;

    /**
     * Delete all unverified records for the subject.
     *
     * @return int the number of deleted records
     */
    public function invalidate(VerificationSubject $subject): int;

    /**
     * Delete records that expired before $now, and verified records older than
     * $verifiedBefore. Retention differs per channel, so callers prune one
     * channel at a time; passing null prunes every channel at the same cutoff.
     *
     * @return int the number of deleted records
     */
    public function prune(CarbonImmutable $now, CarbonImmutable $verifiedBefore, ?Channel $channel = null): int;

    /**
     * Delete every record, or only those for one subject or one channel.
     *
     * @return int the number of deleted records
     */
    public function clear(?VerificationSubject $subject = null, ?Channel $channel = null): int;
}
