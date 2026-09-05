<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Support\OtpVerificationConfig;

/**
 * Copies v1 phone links into the v2 links table.
 *
 * Only the links table is migrated. v1's `phone_verifications` rows are
 * deliberately left behind: they live for one expiration window (five minutes
 * by default), and their hashes are bound to the old, channel-less encoding,
 * so they could never verify under v2 anyway.
 *
 * Safe to run on a live table and safe to run twice — rows are inserted with
 * insert-or-ignore, so both unique indexes on the target table act as the
 * backstop, and nothing in the source table is read-locked or modified.
 */
final class MigrateV1Command extends Command
{
    protected $signature = 'otp-verification:migrate-v1
        {--from=phone_verification_links : The v1 links table to copy from}
        {--chunk=500 : How many rows to copy per batch}
        {--dry-run : Report what would be copied without writing anything}';

    protected $description = 'Copy v1 phone verification links into the v2 verification_links table';

    public function handle(ConnectionResolverInterface $connections, OtpVerificationConfig $config): int
    {
        $connection = $connections->connection();

        $source = $this->stringOption('from', 'phone_verification_links');
        $target = $config->linksTable();

        if (! $connection->getSchemaBuilder()->hasTable($source)) {
            $this->info("No `{$source}` table found — nothing to migrate.");

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->stringOption('chunk', '500'));
        $dryRun = (bool) $this->option('dry-run');
        $sms = Channel::SMS;

        $total = 0;
        $copied = 0;

        $connection->table($source)->orderBy('id')->chunk($chunk, function ($rows) use (
            $connection, $target, $sms, $dryRun, &$total, &$copied
        ): void {
            $payload = [];

            foreach ($rows as $row) {
                $total++;

                $phone = $row->phone ?? null;
                $type = $row->verifiable_type ?? null;
                $id = $row->verifiable_id ?? null;

                if (! is_string($phone) || $phone === '' || ! is_string($type) || $id === null) {
                    continue;
                }

                $payload[] = [
                    'id' => (string) Str::uuid(),
                    'identifier' => $phone,
                    'channel' => $sms,
                    'verifiable_type' => $type,
                    'verifiable_id' => $id,
                    'created_at' => $row->created_at ?? null,
                    'updated_at' => $row->updated_at ?? null,
                ];
            }

            if ($payload === [] || $dryRun) {
                return;
            }

            $copied += $connection->table($target)->insertOrIgnore($payload);
        });

        if ($dryRun) {
            $this->info("Dry run: {$total} v1 link(s) found in `{$source}`; none written.");
            $this->line('Rows already present in the target table are skipped on a real run.');

            return self::SUCCESS;
        }

        $skipped = $total - $copied;

        $this->info("Copied {$copied} of {$total} v1 link(s) into `{$target}` ({$skipped} already present or conflicting).");

        return self::SUCCESS;
    }

    private function stringOption(string $key, string $default): string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
