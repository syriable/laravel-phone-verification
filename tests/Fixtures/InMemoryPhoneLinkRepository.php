<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Syriable\PhoneVerification\Contracts\PhoneLinkRepository;

final class InMemoryPhoneLinkRepository implements PhoneLinkRepository
{
    /** @var array<string, Model> */
    private array $links = [];

    public function link(string $phone, Model $verifiable): bool
    {
        if ($this->isLinkedToAnother($phone, $verifiable)) {
            return false;
        }

        $this->links[$phone] = $verifiable;

        return true;
    }

    public function unlink(string $phone): int
    {
        if (! isset($this->links[$phone])) {
            return 0;
        }

        unset($this->links[$phone]);

        return 1;
    }

    public function linkedTo(string $phone): ?Model
    {
        return $this->links[$phone] ?? null;
    }

    public function phoneFor(Model $verifiable): ?string
    {
        foreach ($this->links as $phone => $linked) {
            if ($linked->is($verifiable)) {
                return $phone;
            }
        }

        return null;
    }

    public function isLinkedToAnother(string $phone, Model $verifiable): bool
    {
        $existing = $this->links[$phone] ?? null;

        return $existing instanceof Model && ! $existing->is($verifiable);
    }
}
