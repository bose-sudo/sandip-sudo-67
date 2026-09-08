<?php

namespace App\Acl\Providers;

use App\Acl\Services\MenuBuilderService;
use App\Acl\Services\PermissionResolver;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AclServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge default config
        $this->mergeConfigFrom(__DIR__ . '/../../../config/acl.php', 'acl');

        // Bind services as singletons
        $this->app->singleton(PermissionResolver::class);
        $this->app->singleton(MenuBuilderService::class, function ($app) {
            return new MenuBuilderService($app->make(PermissionResolver::class));
        });
    }

    public function boot(): void
    {
        // ── Publish config ────────────────────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../../../config/acl.php' => config_path('acl.php'),
        ], 'acl-config');

        // ── Publish migrations ────────────────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../../../database/migrations' => database_path('migrations'),
        ], 'acl-migrations');

        // ── Publish seeders ───────────────────────────────────────────────────
        // $this->publishes([
        //     __DIR__ . '/../../../database/seeders' => database_path('seeders'),
        // ], 'acl-seeders');

        // ── Load routes ───────────────────────────────────────────────────────
        $this->loadRoutesFrom(__DIR__ . '/../../../routes/api.php');

        // ── Register Blade Directives ─────────────────────────────────────────
        $this->registerBladeDirectives();
    }

    protected function registerBladeDirectives(): void
    {
        /**
         * @permission('employee.create')
         *   <button>Create Employee</button>
         * @endpermission
         */
        Blade::directive('permission', function (string $expression) {
            return "<?php if(auth()->check() && auth()->user()->hasPermission({$expression})): ?>";
        });

        Blade::directive('endpermission', function () {
            return '<?php endif; ?>';
        });

        /**
         * @role('admin')
         *   <div>Admin Only</div>
         * @endrole
         */
        Blade::directive('role', function (string $expression) {
            return "<?php if(auth()->check() && auth()->user()->hasRole({$expression})): ?>";
        });

        Blade::directive('endrole', function () {
            return '<?php endif; ?>';
        });

        /**
         * @anypermission(['employee.create', 'employee.edit'])
         *   <div>Create or Edit</div>
         * @endanypermission
         */
        Blade::directive('anypermission', function (string $expression) {
            return "<?php if(auth()->check() && auth()->user()->hasAnyPermission({$expression})): ?>";
        });

        Blade::directive('endanypermission', function () {
            return '<?php endif; ?>';
        });

        /**
         * @anyrole(['admin', 'hr-manager'])
         *   <div>Admin or HR Manager</div>
         * @endanyrole
         */
        Blade::directive('anyrole', function (string $expression) {
            return "<?php if(auth()->check() && auth()->user()->hasAnyRole({$expression})): ?>";
        });

        Blade::directive('endanyrole', function () {
            return '<?php endif; ?>';
        });
    }
}
