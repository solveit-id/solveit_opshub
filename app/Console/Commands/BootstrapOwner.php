<?php

namespace App\Console\Commands;

use App\Application\IdentityAccess\InitialOwnerService;
use Illuminate\Console\Command;
use Throwable;

class BootstrapOwner extends Command
{
    protected $signature = 'opshub:bootstrap-owner {email : Owner email} {--name= : Owner display name} {--organization=Solveit Indonesia : Organization name}';

    protected $description = 'Create the one-time initial Owner and organization without exposing a password argument.';

    public function handle(InitialOwnerService $service): int
    {
        $name = $this->option('name') ?: $this->ask('Owner display name');
        $password = $this->secret('Owner password');
        $confirmation = $this->secret('Confirm owner password');

        if (! is_string($name) || $name === '' || ! is_string($password) || $password === '' || $password !== $confirmation) {
            $this->error('A name and matching non-empty password are required.');

            return self::FAILURE;
        }

        try {
            $service->bootstrap((string) $this->option('organization'), $name, (string) $this->argument('email'), $password);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Owner bootstrap could not be completed.');

            return self::FAILURE;
        }

        $this->info('Initial Owner created. Store and rotate deployment bootstrap material outside the application.');

        return self::SUCCESS;
    }
}
