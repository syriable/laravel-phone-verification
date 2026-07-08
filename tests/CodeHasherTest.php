<?php

declare(strict_types=1);

use Syriable\PhoneVerification\Exceptions\InvalidConfiguration;
use Syriable\PhoneVerification\Hashing\HmacCodeHasher;

it('hashes deterministically for the same phone and code', function (): void {
    $hasher = new HmacCodeHasher('secret-key');

    expect($hasher->hash('+31612345678', '123456'))
        ->toBe($hasher->hash('+31612345678', '123456'));
});

it('produces a hash that does not reveal the code', function (): void {
    $hasher = new HmacCodeHasher('secret-key');

    expect($hasher->hash('+31612345678', '123456'))->not->toContain('123456');
});

it('verifies a matching code', function (): void {
    $hasher = new HmacCodeHasher('secret-key');

    $hash = $hasher->hash('+31612345678', '123456');

    expect($hasher->verify('+31612345678', '123456', $hash))->toBeTrue();
});

it('rejects a non matching code', function (): void {
    $hasher = new HmacCodeHasher('secret-key');

    $hash = $hasher->hash('+31612345678', '123456');

    expect($hasher->verify('+31612345678', '654321', $hash))->toBeFalse();
});

it('binds the hash to the phone number', function (): void {
    $hasher = new HmacCodeHasher('secret-key');

    $hash = $hasher->hash('+31612345678', '123456');

    expect($hasher->verify('+31687654321', '123456', $hash))->toBeFalse();
});

it('produces different hashes with different keys', function (): void {
    expect((new HmacCodeHasher('key-one'))->hash('+31612345678', '123456'))
        ->not->toBe((new HmacCodeHasher('key-two'))->hash('+31612345678', '123456'));
});

it('refuses to operate without a key', function (): void {
    new HmacCodeHasher('');
})->throws(InvalidConfiguration::class, 'app.key');
