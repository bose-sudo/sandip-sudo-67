<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Models\AdminUser;
use App\Models\DealerUserMap;
use App\Models\UserRelationship;
use App\Observers\AdminUserObserver;
use App\Observers\DealerMappingObserver;
use App\Observers\UserRelationshipObserver;
// use App\Services\BorrowerListService;
use App\Services\BorrowerVisibilityService;
use App\Services\DealerMappingService;
use App\Services\FullAccessService;
use App\Services\UserHierarchyService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        
        $this->app->singleton(UserHierarchyService::class);
        $this->app->singleton(DealerMappingService::class);
        $this->app->singleton(BorrowerVisibilityService::class);
        // $this->app->singleton(BorrowerListService::class);
        $this->app->singleton(FullAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keeps user_hierarchy_closure in sync with user_relationships edges
        UserRelationship::observe(UserRelationshipObserver::class);

        // Busts per-user visible-dealer cache when mappings change
        DealerUserMap::observe(DealerMappingObserver::class);

        // Auto-grants is_all_borrower_accessible when a user's type is
        // configured in full_access_user_types
        AdminUser::observe(AdminUserObserver::class);
    }
}
