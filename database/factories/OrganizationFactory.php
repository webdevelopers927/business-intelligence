<?php

namespace Database\Factories;

use App\Enums\Industry;
use App\Enums\OrganizationSize;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'industry' => Industry::Retail,
            'size' => OrganizationSize::Small,
            'timezone' => 'Asia/Karachi',
            'currency' => 'PKR',
            'onboarded_at' => now(),
        ];
    }

    /** Attach a member with the given role after creation. */
    public function withMember(User $user, Role $role = Role::Owner): static
    {
        return $this->afterCreating(function (Organization $organization) use ($user, $role) {
            Membership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $role,
                'joined_at' => now(),
            ]);
        });
    }
}
