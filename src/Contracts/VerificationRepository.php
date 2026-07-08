<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Contracts;

use Carbon\CarbonImmutable;
use Syriable\PhoneVerification\Support\VerificationRecord;

interface VerificationRepository
{
    /**
     * Persist a new verification record.
     */
    public function create(
        string $phone,
        string $codeHash,
        CarbonImmutable $expiresAt,
        int $resendCount = 0,
    ): VerificationRecord;

    /**
     * The most recent unverified record for the phone number, if any.
     * Implementations must guarantee at most one unverified record exists
     * per phone number (invalidate() runs before every create()).
     */
    public function findActive(string $phone): ?VerificationRecord;

    /**
     * The most recent successfully verified record for the phone number.
     */
    public function findVerified(string $phone): ?VerificationRecord;

    /**
     * When a code was last sent to the phone number, verified or not.
     */
    public function lastSentAt(string $phone): ?CarbonImmutable;

    /**
     * Atomically increment the attempt counter and return the fresh record.
     */
    public function incrementAttempts(VerificationRecord $record): VerificationRecord;

    /**
     * Mark the record as successfully verified at the given moment.
     */
    public function markVerified(VerificationRecord $record, CarbonImmutable $verifiedAt): VerificationRecord;

    /**
     * Delete all unverified records for the phone number.
     *
     * @return int the number of deleted records
     */
    public function invalidate(string $phone): int;

    /**
     * Delete records that expired before $now, and verified records older
     * than $verifiedBefore.
     *
     * @return int the number of deleted records
     */
    public function prune(CarbonImmutable $now, CarbonImmutable $verifiedBefore): int;

    /**
     * Delete every record, or all records for a single phone number.
     *
     * @return int the number of deleted records
     */
    public function clear(?string $phone = null): int;
}
