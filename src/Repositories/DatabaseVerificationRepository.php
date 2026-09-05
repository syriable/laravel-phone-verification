<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Repositories;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Contracts\VerificationRepository;
use Syriable\OtpVerification\Models\Verification;
use Syriable\OtpVerification\Support\OtpVerificationConfig;
use Syriable\OtpVerification\Support\VerificationRecord;
use Syriable\OtpVerification\Support\VerificationSubject;

final readonly class DatabaseVerificationRepository implements VerificationRepository
{
    public function __construct(
        private OtpVerificationConfig $config,
    ) {}

    public function create(
        VerificationSubject $subject,
        string $codeHash,
        CarbonImmutable $expiresAt,
        int $resendCount = 0,
    ): VerificationRecord {
        $model = $this->newQuery()->create([
            'identifier' => $subject->identifier,
            'channel' => $subject->channel,
            'code_hash' => $codeHash,
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'resend_count' => $resendCount,
        ]);

        return $this->toRecord($model);
    }

    public function findActive(VerificationSubject $subject): ?VerificationRecord
    {
        $model = $this->queryFor($subject)
            ->whereNull('verified_at')
            ->latest()
            ->first();

        return $model === null ? null : $this->toRecord($model);
    }

    public function findVerified(VerificationSubject $subject): ?VerificationRecord
    {
        $model = $this->queryFor($subject)
            ->whereNotNull('verified_at')
            ->latest('verified_at')
            ->first();

        return $model === null ? null : $this->toRecord($model);
    }

    public function lastSentAt(VerificationSubject $subject): ?CarbonImmutable
    {
        $lastSentAt = $this->queryFor($subject)->max('created_at');

        if ($lastSentAt instanceof DateTimeInterface) {
            return CarbonImmutable::instance($lastSentAt);
        }

        return is_string($lastSentAt) && $lastSentAt !== ''
            ? CarbonImmutable::parse($lastSentAt)
            : null;
    }

    public function incrementAttempts(VerificationRecord $record): VerificationRecord
    {
        $this->newQuery()->whereKey($record->id)->increment('attempts');

        return $this->toRecord($this->newQuery()->findOrFail($record->id));
    }

    public function markVerified(VerificationRecord $record, CarbonImmutable $verifiedAt): VerificationRecord
    {
        $this->newQuery()->whereKey($record->id)->update(['verified_at' => $verifiedAt]);

        return $this->toRecord($this->newQuery()->findOrFail($record->id));
    }

    public function invalidate(VerificationSubject $subject): int
    {
        return $this->delete($this->queryFor($subject)->whereNull('verified_at'));
    }

    public function prune(CarbonImmutable $now, CarbonImmutable $verifiedBefore, ?Channel $channel = null): int
    {
        $expired = $this->delete($this->scopeToChannel($this->newQuery(), $channel)
            ->whereNull('verified_at')
            ->where('expires_at', '<=', $now));

        $staleVerified = $this->delete($this->scopeToChannel($this->newQuery(), $channel)
            ->whereNotNull('verified_at')
            ->where('verified_at', '<', $verifiedBefore));

        return $expired + $staleVerified;
    }

    public function clear(?VerificationSubject $subject = null, ?Channel $channel = null): int
    {
        $query = $subject instanceof VerificationSubject
            ? $this->queryFor($subject)
            : $this->scopeToChannel($this->newQuery(), $channel);

        return $this->delete($query);
    }

    /**
     * @return Builder<Verification>
     */
    private function newQuery(): Builder
    {
        $model = $this->config->verificationModel();

        return $model::query();
    }

    /**
     * @return Builder<Verification>
     */
    private function queryFor(VerificationSubject $subject): Builder
    {
        return $this->newQuery()
            ->where('identifier', $subject->identifier)
            ->where('channel', $subject->channel->value);
    }

    /**
     * @param  Builder<Verification>  $query
     * @return Builder<Verification>
     */
    private function scopeToChannel(Builder $query, ?Channel $channel): Builder
    {
        return $channel instanceof Channel
            ? $query->where('channel', $channel->value)
            : $query;
    }

    /**
     * @param  Builder<Verification>  $query
     */
    private function delete(Builder $query): int
    {
        return $query->delete();
    }

    private function toRecord(Verification $model): VerificationRecord
    {
        return new VerificationRecord(
            id: $model->id,
            identifier: $model->identifier,
            channel: $model->channel,
            codeHash: $model->code_hash,
            expiresAt: $model->expires_at,
            verifiedAt: $model->verified_at,
            attempts: $model->attempts,
            resendCount: $model->resend_count,
            createdAt: $model->created_at ?? CarbonImmutable::now(),
        );
    }
}
