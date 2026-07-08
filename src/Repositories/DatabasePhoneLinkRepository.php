<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Syriable\PhoneVerification\Contracts\PhoneLinkRepository;
use Syriable\PhoneVerification\Models\PhoneVerificationLink;

final class DatabasePhoneLinkRepository implements PhoneLinkRepository
{
    public function link(string $phone, Model $verifiable): bool
    {
        if ($this->isLinkedToAnother($phone, $verifiable)) {
            return false;
        }

        try {
            PhoneVerificationLink::query()->updateOrCreate(
                ['phone' => $phone],
                ['verifiable_type' => $verifiable->getMorphClass(), 'verifiable_id' => $verifiable->getKey()],
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function unlink(string $phone): int
    {
        $deleted = PhoneVerificationLink::query()->where('phone', $phone)->delete();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }

    public function linkedTo(string $phone): ?Model
    {
        return $this->findLink($phone)?->verifiable;
    }

    public function phoneFor(Model $verifiable): ?string
    {
        $phone = PhoneVerificationLink::query()
            ->where('verifiable_type', $verifiable->getMorphClass())
            ->where('verifiable_id', $verifiable->getKey())
            ->value('phone');

        return is_string($phone) ? $phone : null;
    }

    public function isLinkedToAnother(string $phone, Model $verifiable): bool
    {
        $existing = $this->findLink($phone);

        return $existing instanceof PhoneVerificationLink && ! $this->isSameModel($existing, $verifiable);
    }

    private function findLink(string $phone): ?PhoneVerificationLink
    {
        return PhoneVerificationLink::query()->where('phone', $phone)->first();
    }

    private function isSameModel(PhoneVerificationLink $link, Model $verifiable): bool
    {
        return $link->verifiable_type === $verifiable->getMorphClass()
            && $this->stringKey($link->verifiable_id) === $this->stringKey($verifiable->getKey());
    }

    private function stringKey(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
    }
}
