<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SeedIfEmpty extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:seed-if-empty {--retries=5} {--delay=3}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Waits for the database to be ready and runs the DatabaseSeeder if the users table is empty, with error logging.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $maxRetries = (int) $this->option('retries');
        $delaySeconds = (int) $this->option('delay');

        if (! $this->waitForDatabaseConnection($maxRetries, $delaySeconds)) {
            $message = 'SeedIfEmpty: could not establish a database connection after '
                . $maxRetries . ' attempts. Aborting seed.';
            $this->error($message);
            Log::error($message);

            return self::FAILURE;
        }

        try {
            if (! $this->usersTableExists()) {
                $message = 'SeedIfEmpty: users table does not exist yet. Skipping seed (run migrations first).';
                $this->warn($message);
                Log::warning($message);

                return self::FAILURE;
            }

            $usersCount = User::count();

            if ($usersCount > 0) {
                $this->info("SeedIfEmpty: users table already has {$usersCount} row(s). Skipping seed.");

                return self::SUCCESS;
            }

            $this->info('SeedIfEmpty: users table is empty. Running DatabaseSeeder...');

            $this->call('db:seed', [
                '--class' => DatabaseSeeder::class,
                '--force' => true,
            ]);

            $newCount = User::count();

            if ($newCount === 0) {
                $message = 'SeedIfEmpty: DatabaseSeeder ran but the users table is still empty. Check the seeder logic.';
                $this->error($message);
                Log::error($message);

                return self::FAILURE;
            }

            $this->info("SeedIfEmpty: seeding complete. {$newCount} user(s) now in the database.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = 'SeedIfEmpty: exception thrown while seeding the database: ' . $e->getMessage();
            $this->error($message);
            Log::error($message, [
                'exception' => $e,
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Attempt to connect to the database, retrying with a delay between attempts.
     */
    protected function waitForDatabaseConnection(int $maxRetries, int $delaySeconds): bool
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                DB::connection()->getPdo();

                return true;
            } catch (Throwable $e) {
                $message = "SeedIfEmpty: database not ready (attempt {$attempt}/{$maxRetries}): " . $e->getMessage();
                $this->warn($message);
                Log::warning($message);

                if ($attempt < $maxRetries) {
                    sleep($delaySeconds);
                }
            }
        }

        return false;
    }

    /**
     * Determine whether the users table exists.
     */
    protected function usersTableExists(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('users');
        } catch (Throwable $e) {
            Log::error('SeedIfEmpty: failed to check if users table exists: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return false;
        }
    }
}
