<?php

namespace App\Providers;

use App\Models\Organization;
use App\Models\User;
use App\Policies\GroupTechnicalPolicy;
use App\Policies\OrganizationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);

        Gate::define(
            'group.technical',
            fn (User $user, string $capability): bool => app(GroupTechnicalPolicy::class)->allows($user, $capability),
        );
    }
}
