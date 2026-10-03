<?php

namespace App\Application\IdentityAccess;

use App\Domain\IdentityAccess\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class InitialOwnerService
{
    public function bootstrap(string $organizationName, string $name, string $email, string $password): User
    {
        if (Membership::query()->where('role', Role::Owner->value)->exists()) {
            throw new LogicException('An Owner membership already exists.');
        }

        return DB::transaction(function () use ($organizationName, $name, $email, $password): User {
            $organization = Organization::create([
                'name' => $organizationName,
                'timezone' => config('opshub.timezone'),
                'is_active' => true,
            ]);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);

            Membership::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => Role::Owner,
                'is_active' => true,
            ]);

            return $user;
        });
    }
}
