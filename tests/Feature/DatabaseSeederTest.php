<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnv = [];

    public function test_existing_super_admin_password_is_preserved_by_default(): void
    {
        $this->setEnv('SYNC360_SUPER_ADMIN_EMAIL', 'ops@example.com');
        $this->setEnv('SYNC360_SUPER_ADMIN_NAME', 'Operations Admin');
        $this->setEnv('SYNC360_SUPER_ADMIN_PASSWORD', 'seed-password');
        $this->setEnv('SYNC360_RESET_SUPER_ADMIN_PASSWORD', 'false');

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $admin = \App\Models\User::query()->where('email', 'ops@example.com')->firstOrFail();
        $admin->password = 'manually-updated-password';
        $admin->save();

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $admin->refresh();

        $this->assertTrue($admin->is_admin);
        $this->assertSame('Operations Admin', $admin->name);
        $this->assertTrue(Hash::check('manually-updated-password', $admin->password));
        $this->assertFalse(Hash::check('seed-password', $admin->password));
    }

    public function test_super_admin_password_can_be_reset_explicitly(): void
    {
        $this->setEnv('SYNC360_SUPER_ADMIN_EMAIL', 'ops@example.com');
        $this->setEnv('SYNC360_SUPER_ADMIN_NAME', 'Operations Admin');
        $this->setEnv('SYNC360_SUPER_ADMIN_PASSWORD', 'seed-password');
        $this->setEnv('SYNC360_RESET_SUPER_ADMIN_PASSWORD', 'false');

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $admin = \App\Models\User::query()->where('email', 'ops@example.com')->firstOrFail();
        $admin->password = 'manually-updated-password';
        $admin->save();

        $this->setEnv('SYNC360_RESET_SUPER_ADMIN_PASSWORD', 'true');

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $admin->refresh();

        $this->assertTrue(Hash::check('seed-password', $admin->password));
    }

    private function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $this->originalEnv[$key] = getenv($key);
        }

        putenv(sprintf('%s=%s', $key, $value));
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        parent::tearDown();
    }
}
