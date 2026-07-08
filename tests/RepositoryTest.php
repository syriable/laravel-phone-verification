<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Syriable\PhoneVerification\Models\PhoneVerification as PhoneVerificationModel;
use Syriable\PhoneVerification\Repositories\DatabaseVerificationRepository;

beforeEach(function (): void {
    $this->repository = new DatabaseVerificationRepository;
});

function expiresInFiveMinutes(): CarbonImmutable
{
    return CarbonImmutable::now()->addMinutes(5);
}

it('creates a record with sensible defaults', function (): void {
    $record = $this->repository->create('+31612345678', 'hash', expiresInFiveMinutes());

    expect($record->id)->toBeUuid()
        ->and($record->phone)->toBe('+31612345678')
        ->and($record->codeHash)->toBe('hash')
        ->and($record->attempts)->toBe(0)
        ->and($record->resendCount)->toBe(0)
        ->and($record->isVerified())->toBeFalse();
});

it('finds the active record for a phone number', function (): void {
    $this->repository->create('+31612345678', 'hash', expiresInFiveMinutes());
    $this->repository->create('+31687654321', 'other', expiresInFiveMinutes());

    expect($this->repository->findActive('+31612345678')?->codeHash)->toBe('hash')
        ->and($this->repository->findActive('+31600000000'))->toBeNull();
});

it('does not consider verified records active', function (): void {
    $record = $this->repository->create('+31612345678', 'hash', expiresInFiveMinutes());
    $this->repository->markVerified($record, CarbonImmutable::now());

    expect($this->repository->findActive('+31612345678'))->toBeNull()
        ->and($this->repository->findVerified('+31612345678')?->id)->toBe($record->id);
});

it('tracks when a code was last sent', function (): void {
    expect($this->repository->lastSentAt('+31612345678'))->toBeNull();

    $this->repository->create('+31612345678', 'hash', expiresInFiveMinutes());

    expect($this->repository->lastSentAt('+31612345678'))
        ->toBeInstanceOf(CarbonImmutable::class);
});

it('still knows the last sent time after the record was verified', function (): void {
    $record = $this->repository->create('+31612345678', 'hash', expiresInFiveMinutes());
    $this->repository->markVerified($record, CarbonImmutable::now());

    expect($this->repository->lastSentAt('+31612345678'))->not->toBeNull();
});

it('increments attempts', function (): void {
    $record = $this->repository->create('+31612345678', 'hash', expiresInFiveMinutes());

    $record = $this->repository->incrementAttempts($record);
    $record = $this->repository->incrementAttempts($record);

    expect($record->attempts)->toBe(2)
        ->and(PhoneVerificationModel::query()->sole()->attempts)->toBe(2);
});

it('marks a record as verified', function (): void {
    $verifiedAt = CarbonImmutable::now();
    $record = $this->repository->create('+31612345678', 'hash', expiresInFiveMinutes());

    $record = $this->repository->markVerified($record, $verifiedAt);

    // database timestamps have second precision
    expect($record->isVerified())->toBeTrue()
        ->and($record->verifiedAt?->toDateTimeString())->toBe($verifiedAt->toDateTimeString());
});

it('invalidates only unverified records for the phone number', function (): void {
    $verified = $this->repository->create('+31612345678', 'a', expiresInFiveMinutes());
    $this->repository->markVerified($verified, CarbonImmutable::now());
    $this->repository->create('+31612345678', 'b', expiresInFiveMinutes());
    $this->repository->create('+31687654321', 'c', expiresInFiveMinutes());

    expect($this->repository->invalidate('+31612345678'))->toBe(1)
        ->and($this->repository->findVerified('+31612345678'))->not->toBeNull()
        ->and($this->repository->findActive('+31687654321'))->not->toBeNull();
});

it('prunes expired and stale verified records', function (): void {
    $now = CarbonImmutable::now();

    // expired, unverified: pruned
    $this->repository->create('+31600000001', 'a', $now->subMinute());

    // still valid: kept
    $this->repository->create('+31600000002', 'b', $now->addMinutes(5));

    // verified recently: kept
    $freshlyVerified = $this->repository->create('+31600000003', 'c', $now->addMinutes(5));
    $this->repository->markVerified($freshlyVerified, $now);

    // verified long ago: pruned
    $staleVerified = $this->repository->create('+31600000004', 'd', $now->addMinutes(5));
    $this->repository->markVerified($staleVerified, $now->subDays(30));

    $deleted = $this->repository->prune($now, $now->subDays(7));

    expect($deleted)->toBe(2)
        ->and(PhoneVerificationModel::query()->count())->toBe(2)
        ->and($this->repository->findActive('+31600000002'))->not->toBeNull()
        ->and($this->repository->findVerified('+31600000003'))->not->toBeNull();
});

it('clears records for a single phone number', function (): void {
    $this->repository->create('+31612345678', 'a', expiresInFiveMinutes());
    $this->repository->create('+31687654321', 'b', expiresInFiveMinutes());

    expect($this->repository->clear('+31612345678'))->toBe(1)
        ->and(PhoneVerificationModel::query()->count())->toBe(1);
});

it('clears all records', function (): void {
    $this->repository->create('+31612345678', 'a', expiresInFiveMinutes());
    $this->repository->create('+31687654321', 'b', expiresInFiveMinutes());

    expect($this->repository->clear())->toBe(2)
        ->and(PhoneVerificationModel::query()->count())->toBe(0);
});

it('uses the configured table name', function (): void {
    expect((new PhoneVerificationModel)->getTable())->toBe('phone_verifications');

    config()->set('phone-verification.table', 'custom_verifications');

    expect((new PhoneVerificationModel)->getTable())->toBe('custom_verifications');
});
