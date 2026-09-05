<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Syriable\OtpVerification\Models\VerificationLink;

function createV1LinksTable(): void
{
    Schema::create('phone_verification_links', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('phone')->unique();
        $table->morphs('verifiable');
        $table->timestamps();
    });
}

function insertV1Link(string $phone, int $id = 1): void
{
    DB::table('phone_verification_links')->insert([
        'id' => (string) Str::uuid(),
        'phone' => $phone,
        'verifiable_type' => 'users',
        'verifiable_id' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('otp-verification:migrate-v1', function (): void {
    it('does nothing when there is no v1 table', function (): void {
        test()->artisan('otp-verification:migrate-v1')->assertSuccessful();

        expect(VerificationLink::query()->count())->toBe(0);
    });

    it('copies v1 links onto the sms channel', function (): void {
        createV1LinksTable();
        insertV1Link('+31612345678', 1);
        insertV1Link('+31699999999', 2);

        test()->artisan('otp-verification:migrate-v1')->assertSuccessful();

        $links = VerificationLink::query()->orderBy('identifier')->get();

        expect($links)->toHaveCount(2)
            ->and($links[0]->identifier)->toBe('+31612345678')
            ->and($links[0]->channel->isSms())->toBeTrue();
    });

    it('writes nothing on a dry run', function (): void {
        createV1LinksTable();
        insertV1Link('+31612345678');

        test()->artisan('otp-verification:migrate-v1 --dry-run')->assertSuccessful();

        expect(VerificationLink::query()->count())->toBe(0);
    });

    it('is idempotent, so running it twice changes nothing', function (): void {
        createV1LinksTable();
        insertV1Link('+31612345678', 1);

        test()->artisan('otp-verification:migrate-v1')->assertSuccessful();
        test()->artisan('otp-verification:migrate-v1')->assertSuccessful();

        expect(VerificationLink::query()->count())->toBe(1);
    });

    it('leaves the v1 table untouched', function (): void {
        createV1LinksTable();
        insertV1Link('+31612345678');

        test()->artisan('otp-verification:migrate-v1')->assertSuccessful();

        expect(DB::table('phone_verification_links')->count())->toBe(1);
    });
});
