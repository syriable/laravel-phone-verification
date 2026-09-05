<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Contracts\LinkRepository;
use Syriable\OtpVerification\Models\VerificationLink;
use Syriable\OtpVerification\Support\OtpVerificationConfig;
use Syriable\OtpVerification\Support\VerificationSubject;

final readonly class DatabaseLinkRepository implements LinkRepository
{
    public function __construct(
        private OtpVerificationConfig $config,
    ) {}

    public function link(VerificationSubject $subject, Model $verifiable): bool
    {
        if ($this->isLinkedToAnother($subject, $verifiable)) {
            return false;
        }

        $existingForModel = $this->identifierFor($verifiable, $subject->channel);

        // A model holds at most one verified identifier per channel. Replacing
        // one is an explicit unlink-then-link, never a silent overwrite.
        if ($existingForModel !== null && $existingForModel !== $subject->identifier) {
            return false;
        }

        try {
            $this->newQuery()->updateOrCreate(
                [
                    'identifier' => $subject->identifier,
                    'channel' => $subject->channel->value,
                ],
                [
                    'verifiable_type' => $verifiable->getMorphClass(),
                    'verifiable_id' => $verifiable->getKey(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent link for the same subject or
            // the same (model, channel). The other writer won; report no change.
            return false;
        }

        return true;
    }

    public function unlink(VerificationSubject $subject): int
    {
        $deleted = $this->queryFor($subject)->delete();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }

    public function linkedTo(VerificationSubject $subject): ?Model
    {
        return $this->findLink($subject)?->verifiable;
    }

    public function identifierFor(Model $verifiable, Channel $channel): ?string
    {
        $identifier = $this->newQuery()
            ->where('verifiable_type', $verifiable->getMorphClass())
            ->where('verifiable_id', $verifiable->getKey())
            ->where('channel', $channel->value)
            ->value('identifier');

        return is_string($identifier) ? $identifier : null;
    }

    public function isLinkedToAnother(VerificationSubject $subject, Model $verifiable): bool
    {
        $existing = $this->findLink($subject);

        return $existing instanceof VerificationLink && ! $this->isSameModel($existing, $verifiable);
    }

    private function findLink(VerificationSubject $subject): ?VerificationLink
    {
        return $this->queryFor($subject)->first();
    }

    /**
     * @return Builder<VerificationLink>
     */
    private function newQuery(): Builder
    {
        $model = $this->config->linkModel();

        return $model::query();
    }

    /**
     * @return Builder<VerificationLink>
     */
    private function queryFor(VerificationSubject $subject): Builder
    {
        return $this->newQuery()
            ->where('identifier', $subject->identifier)
            ->where('channel', $subject->channel->value);
    }

    private function isSameModel(VerificationLink $link, Model $verifiable): bool
    {
        return $link->verifiable_type === $verifiable->getMorphClass()
            && $this->stringKey($link->verifiable_id) === $this->stringKey($verifiable->getKey());
    }

    private function stringKey(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
    }
}
