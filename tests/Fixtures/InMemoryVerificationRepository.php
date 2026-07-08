<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Syriable\PhoneVerification\Contracts\VerificationRepository;
use Syriable\PhoneVerification\Support\VerificationRecord;

final class InMemoryVerificationRepository implements VerificationRepository
{
    /** @var array<string, VerificationRecord> */
    private array $records = [];

    private int $nextId = 1;

    public function create(
        string $phone,
        string $codeHash,
        CarbonImmutable $expiresAt,
        int $resendCount = 0,
    ): VerificationRecord {
        $record = new VerificationRecord(
            id: (string) $this->nextId++,
            phone: $phone,
            codeHash: $codeHash,
            expiresAt: $expiresAt,
            verifiedAt: null,
            attempts: 0,
            resendCount: $resendCount,
            createdAt: CarbonImmutable::now(),
        );

        return $this->records[$record->id] = $record;
    }

    public function findActive(string $phone): ?VerificationRecord
    {
        return $this->latest($phone, verified: false);
    }

    public function findVerified(string $phone): ?VerificationRecord
    {
        return $this->latest($phone, verified: true);
    }

    public function lastSentAt(string $phone): ?CarbonImmutable
    {
        $sentAt = array_map(
            fn (VerificationRecord $record): CarbonImmutable => $record->createdAt,
            array_filter($this->records, fn (VerificationRecord $record): bool => $record->phone === $phone),
        );

        return $sentAt === [] ? null : max($sentAt);
    }

    public function incrementAttempts(VerificationRecord $record): VerificationRecord
    {
        return $this->replace($record, attempts: $record->attempts + 1);
    }

    public function markVerified(VerificationRecord $record, CarbonImmutable $verifiedAt): VerificationRecord
    {
        return $this->replace($record, verifiedAt: $verifiedAt);
    }

    public function invalidate(string $phone): int
    {
        return $this->deleteWhere(
            fn (VerificationRecord $record): bool => $record->phone === $phone && ! $record->isVerified()
        );
    }

    public function prune(CarbonImmutable $now, CarbonImmutable $verifiedBefore): int
    {
        return $this->deleteWhere(fn (VerificationRecord $record): bool => $record->isVerified()
            ? $record->verifiedAt !== null && $record->verifiedAt->lessThan($verifiedBefore)
            : $record->isExpired($now));
    }

    public function clear(?string $phone = null): int
    {
        return $this->deleteWhere(
            fn (VerificationRecord $record): bool => $phone === null || $record->phone === $phone
        );
    }

    private function latest(string $phone, bool $verified): ?VerificationRecord
    {
        $matches = array_filter(
            $this->records,
            fn (VerificationRecord $record): bool => $record->phone === $phone && $record->isVerified() === $verified,
        );

        return array_reverse($matches)[0] ?? null;
    }

    private function replace(
        VerificationRecord $record,
        ?int $attempts = null,
        ?CarbonImmutable $verifiedAt = null,
    ): VerificationRecord {
        return $this->records[$record->id] = new VerificationRecord(
            id: $record->id,
            phone: $record->phone,
            codeHash: $record->codeHash,
            expiresAt: $record->expiresAt,
            verifiedAt: $verifiedAt ?? $record->verifiedAt,
            attempts: $attempts ?? $record->attempts,
            resendCount: $record->resendCount,
            createdAt: $record->createdAt,
        );
    }

    /**
     * @param  callable(VerificationRecord): bool  $shouldDelete
     */
    private function deleteWhere(callable $shouldDelete): int
    {
        $matching = array_filter($this->records, $shouldDelete);

        foreach (array_keys($matching) as $id) {
            unset($this->records[$id]);
        }

        return count($matching);
    }
}
