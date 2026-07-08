<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Syriable\PhoneVerification\Contracts\VerificationRepository;
use Syriable\PhoneVerification\Models\PhoneVerification;
use Syriable\PhoneVerification\Support\VerificationRecord;

final class DatabaseVerificationRepository implements VerificationRepository
{
    public function create(
        string $phone,
        string $codeHash,
        CarbonImmutable $expiresAt,
        int $resendCount = 0,
    ): VerificationRecord {
        $model = PhoneVerification::query()->create([
            'phone' => $phone,
            'code_hash' => $codeHash,
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'resend_count' => $resendCount,
        ]);

        return $this->toRecord($model);
    }

    public function findActive(string $phone): ?VerificationRecord
    {
        $model = $this->queryFor($phone)
            ->whereNull('verified_at')
            ->latest()
            ->first();

        return $model === null ? null : $this->toRecord($model);
    }

    public function findVerified(string $phone): ?VerificationRecord
    {
        $model = $this->queryFor($phone)
            ->whereNotNull('verified_at')
            ->latest('verified_at')
            ->first();

        return $model === null ? null : $this->toRecord($model);
    }

    public function lastSentAt(string $phone): ?CarbonImmutable
    {
        $lastSentAt = $this->queryFor($phone)->max('created_at');

        return is_string($lastSentAt) ? CarbonImmutable::parse($lastSentAt) : null;
    }

    public function incrementAttempts(VerificationRecord $record): VerificationRecord
    {
        PhoneVerification::query()->whereKey($record->id)->increment('attempts');

        return $this->toRecord(PhoneVerification::query()->findOrFail($record->id));
    }

    public function markVerified(VerificationRecord $record, CarbonImmutable $verifiedAt): VerificationRecord
    {
        PhoneVerification::query()->whereKey($record->id)->update(['verified_at' => $verifiedAt]);

        return $this->toRecord(PhoneVerification::query()->findOrFail($record->id));
    }

    public function invalidate(string $phone): int
    {
        return $this->delete($this->queryFor($phone)->whereNull('verified_at'));
    }

    public function prune(CarbonImmutable $now, CarbonImmutable $verifiedBefore): int
    {
        $expired = $this->delete(PhoneVerification::query()
            ->whereNull('verified_at')
            ->where('expires_at', '<=', $now));

        $staleVerified = $this->delete(PhoneVerification::query()
            ->whereNotNull('verified_at')
            ->where('verified_at', '<', $verifiedBefore));

        return $expired + $staleVerified;
    }

    public function clear(?string $phone = null): int
    {
        return $this->delete($phone === null
            ? PhoneVerification::query()
            : $this->queryFor($phone));
    }

    /**
     * @return Builder<PhoneVerification>
     */
    private function queryFor(string $phone): Builder
    {
        return PhoneVerification::query()->where('phone', $phone);
    }

    /**
     * @param  Builder<PhoneVerification>  $query
     */
    private function delete(Builder $query): int
    {
        $deleted = $query->delete();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }

    private function toRecord(PhoneVerification $model): VerificationRecord
    {
        return new VerificationRecord(
            id: $model->id,
            phone: $model->phone,
            codeHash: $model->code_hash,
            expiresAt: $model->expires_at,
            verifiedAt: $model->verified_at,
            attempts: $model->attempts,
            resendCount: $model->resend_count,
            createdAt: $model->created_at ?? CarbonImmutable::now(),
        );
    }
}
