<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('nur:create-admin {email} {name} {--generate : Generate a one-time bootstrap password instead of prompting for one}')]
#[Description('Create or update an administrative NUR WinControl bootstrap user')]
class CreateAdminUserCommand extends Command
{
    public function handle(): int
    {
        $password = $this->resolveBootstrapPassword();

        if ($password === null) {
            $this->components->error('A bootstrap password is required.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => (string) $this->argument('email')],
            [
                'name' => (string) $this->argument('name'),
                'password' => $password,
                'is_admin' => true,
                'must_rotate_password' => true,
                'bootstrap_password_issued_at' => now(),
                'password_rotated_at' => null,
            ],
        );

        $this->components->info("Bootstrap admin ready: {$user->email}");
        $this->line('This account must rotate its password on first login.');

        if ($this->option('generate') || ! $this->input->isInteractive()) {
            $this->newLine();
            $this->warn('One-time bootstrap password');
            $this->line($password);
        }

        return self::SUCCESS;
    }

    private function resolveBootstrapPassword(): ?string
    {
        if ($this->option('generate') || ! $this->input->isInteractive()) {
            return Str::password(20);
        }

        $password = (string) $this->secret('Enter a one-time bootstrap password (leave blank to generate one)');

        if ($password === '') {
            return Str::password(20);
        }

        if (Str::length($password) < 14) {
            $this->components->error('Bootstrap passwords must be at least 14 characters long.');

            return null;
        }

        $confirmation = (string) $this->secret('Confirm the one-time bootstrap password');

        if ($password !== $confirmation) {
            $this->components->error('Bootstrap password confirmation did not match.');

            return null;
        }

        return $password;
    }
}
