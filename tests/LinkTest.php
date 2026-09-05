<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Events\IdentifierLinked;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Models\VerificationLink;
use Syriable\OtpVerification\Tests\Fixtures\VerifiableUser;

function newUser(string $name = 'Ada', ?string $email = null): VerifiableUser
{
    return VerifiableUser::query()->create(['name' => $name, 'email' => $email]);
}

describe('linking a verified identifier to a model', function (): void {
    it('links on successful verification', function (): void {
        $user = newUser();
        $code = sendAndCaptureCode('+31612345678');

        $result = Verification::verify('+31612345678', $code, for: $user);

        expect($result->successful())->toBeTrue()
            ->and(Verification::linkedTo('+31612345678')?->is($user))->toBeTrue()
            ->and(Verification::identifierFor($user))->toBe('+31612345678');
    });

    it('links nothing when verify is called without a model', function (): void {
        $code = sendAndCaptureCode('+31612345678');

        Verification::verify('+31612345678', $code);

        expect(VerificationLink::query()->count())->toBe(0);
    });

    it('lets one model hold a verified phone and a verified email at once', function (): void {
        $user = newUser();

        $phoneCode = sendAndCaptureCode('+31612345678', Channel::sms());
        Verification::verify('+31612345678', $phoneCode, Channel::sms(), $user);

        $mailCode = sendAndCaptureCode('ada@example.com', Channel::mail());
        Verification::verify('ada@example.com', $mailCode, Channel::mail(), $user);

        $user->refresh();

        expect($user->verifiedIdentifier(Channel::sms()))->toBe('+31612345678')
            ->and($user->verifiedIdentifier(Channel::mail()))->toBe('ada@example.com')
            ->and($user->hasVerifiedIdentifier(Channel::sms()))->toBeTrue()
            ->and($user->hasVerifiedEmailAddress())->toBeTrue()
            ->and($user->verificationLinks()->count())->toBe(2);
    });

    it('refuses to link an identifier already owned by another model', function (): void {
        $ada = newUser('Ada');
        $grace = newUser('Grace');

        $code = sendAndCaptureCode('+31612345678');
        Verification::verify('+31612345678', $code, for: $ada);

        travelSeconds(120);
        $second = sendAndCaptureCode('+31612345678');
        $result = Verification::verify('+31612345678', $second, for: $grace);

        expect($result->identifierTakenByAnotherAccount())->toBeTrue()
            ->and(Verification::linkedTo('+31612345678')?->is($ada))->toBeTrue();
    });

    it('refuses to give a model a second identifier on the same channel', function (): void {
        $user = newUser();

        $first = sendAndCaptureCode('+31612345678');
        Verification::verify('+31612345678', $first, for: $user);

        expect(Verification::link('+31699999999', $user))->toBeFalse()
            ->and(Verification::identifierFor($user))->toBe('+31612345678');
    });

    it('allows re-linking after an explicit unlink', function (): void {
        $user = newUser();

        $code = sendAndCaptureCode('+31612345678');
        Verification::verify('+31612345678', $code, for: $user);

        expect(Verification::unlink('+31612345678'))->toBe(1)
            ->and(Verification::link('+31699999999', $user))->toBeTrue()
            ->and(Verification::identifierFor($user))->toBe('+31699999999');
    });

    it('is idempotent when linking the same identifier to the same model', function (): void {
        $user = newUser();

        expect(Verification::link('+31612345678', $user))->toBeTrue()
            ->and(Verification::link('+31612345678', $user))->toBeTrue()
            ->and(VerificationLink::query()->count())->toBe(1);
    });

    it('scopes links by channel', function (): void {
        $ada = newUser('Ada');
        $grace = newUser('Grace');

        Verification::link('alice@example.com', $ada, Channel::sms());
        Verification::link('alice@example.com', $grace, Channel::mail());

        expect(Verification::linkedTo('alice@example.com', Channel::sms())?->is($ada))->toBeTrue()
            ->and(Verification::linkedTo('alice@example.com', Channel::mail())?->is($grace))->toBeTrue();
    });

    it('dispatches IdentifierLinked with the subject', function (): void {
        Event::fake([IdentifierLinked::class]);

        $user = newUser();
        Verification::link('ada@example.com', $user, Channel::mail());

        Event::assertDispatched(
            IdentifierLinked::class,
            static fn (IdentifierLinked $event): bool => $event->subject->identifier === 'ada@example.com'
                && $event->subject->channel->isMail()
        );
    });

    it('reads from an eager-loaded relation instead of querying again', function (): void {
        $user = newUser();
        Verification::link('+31612345678', $user, Channel::sms());

        $loaded = VerifiableUser::query()->with('verificationLinks')->findOrFail($user->getKey());

        DB::enableQueryLog();
        $identifier = $loaded->verifiedIdentifier(Channel::sms());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($identifier)->toBe('+31612345678')
            ->and($queries)->toBeEmpty();
    });
});
