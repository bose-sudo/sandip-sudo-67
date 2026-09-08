# Laravel ACL — Role-Based Access Control System

A production-ready, reusable, and scalable RBAC / Permission Management system for **Laravel 10, 11, and 12**.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Database Design](#2-database-design)
3. [Installation & Setup](#3-installation--setup)
4. [Middleware Registration](#4-middleware-registration)
   - [Laravel 12 (bootstrap/app.php)](#laravel-12--bootstrapappphp)
   - [Laravel 10 / 11 (Kernel.php)](#laravel-10--11--kernelphp)
5. [Usage — Blade](#5-usage--blade)
6. [Usage — Controllers](#6-usage--controllers)
7. [Usage — Route Middleware](#7-usage--route-middleware)
8. [Usage — API (React / Vue SPA)](#8-usage--api-react--vue-spa)
   - [Sidebar Menu API](#sidebar-menu-api)
   - [Header Menu API](#header-menu-api)
   - [React Example](#react-example)
9. [Permission Resolution Priority](#9-permission-resolution-priority)
10. [Admin: Granting & Revoking Permissions](#10-admin-granting--revoking-permissions)
11. [Caching Strategy](#11-caching-strategy)
12. [Scaling Best Practices](#12-scaling-best-practices)
13. [File Structure Reference](#13-file-structure-reference)

---

## 1. Architecture Overview

```
Hierarchical Structure:

Module (optional container)
  └── Menu (sidebar/header, can be dropdown)
        └── Sub-menu (has route_name)
              └── Permission (slug: employee.create → route: employees.create)

Permission Resolution (priority order):
  1. Direct user override (user_permissions.allow = true/false)
  2. Role-based permission (role_permission pivot)
  3. Deny by default
```

### Core Components

| Component | Location | Purpose |
|---|---|---|
| `PermissionResolver` | `Services/` | Core resolution engine with caching |
| `MenuBuilderService` | `Services/` | Builds filtered menu trees |
| `HasPermissions` | `Traits/` | Added to User model |
| `AuthorizesPermissions` | `Traits/` | Added to base Controller |
| `CheckPermission` | `Middleware/` | Route-level guard |
| `CheckRole` | `Middleware/` | Role-level route guard |
| `AclServiceProvider` | `Providers/` | Registers everything |

---

## 2. Database Design

```
modules
  id, name, slug, icon, description, sort_order, is_active

menus
  id, module_id, parent_id, name, slug, icon, type[sidebar|header],
  route_name (null = dropdown), url, sort_order, is_active

permissions
  id, module_id, menu_id, name, slug, route_name, method, description, is_active

roles
  id, name, slug, description, is_active

role_permission (pivot)
  role_id, permission_id

user_roles (pivot)
  user_id, role_id

user_permissions (override pivot)
  user_id, permission_id, allow[bool], reason, granted_by
```

**Key design decisions:**
- `menus.route_name = null` → dropdown only (no direct navigation)
- `user_permissions.allow = false` → explicit **deny** that overrides role permissions
- `granted_by` → full audit trail for compliance
- Permission `slug` is the primary identifier everywhere (`employee.create`)

---

## 3. Installation & Setup

### Step 1 — Copy files into your project

Copy the `app/Acl/` directory into your Laravel application:

```
app/
  Acl/
    Models/          → Module, Menu, Permission, Role
    Traits/          → HasPermissions, AuthorizesPermissions
    Middleware/      → CheckPermission, CheckRole
    Services/        → PermissionResolver, MenuBuilderService
    Http/
      Controllers/Api/  → SidebarMenuController, HeaderMenuController
      Resources/        → MenuResource
    Providers/       → AclServiceProvider
```

Copy `config/acl.php` to your project's `config/` directory.

Copy migration files to `database/migrations/`.

### Step 2 — Register the Service Provider

**Laravel 10/11** — add to `config/app.php`:
```php
'providers' => [
    // ...
    App\Acl\Providers\AclServiceProvider::class,
],
```

**Laravel 12** — add to `bootstrap/providers.php`:
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Acl\Providers\AclServiceProvider::class,  // ← add this
];
```

### Step 3 — Add trait to User model

```php
// app/Models/User.php
use App\Acl\Traits\HasPermissions;

class User extends Authenticatable
{
    use HasPermissions; // ← add this
    // ...
}
```

### Step 4 — Add trait to base Controller

```php
// app/Http/Controllers/Controller.php
use App\Acl\Traits\AuthorizesPermissions;

class Controller extends BaseController
{
    use AuthorizesPermissions; // ← add this
}
```

### Step 5 — Run migrations

```bash
php artisan migrate
```

### Step 6 — Seed example data

```bash
php artisan db:seed --class=EmployeeModuleSeeder
```

### Step 7 — Configure environment

```env
ACL_CACHE_PREFIX=acl
ACL_CACHE_TTL=3600
ACL_CACHE_TAGS=false      # set true if using Redis
ACL_ALLOW_UNREGISTERED=false
```

---

## 4. Middleware Registration

### Laravel 12 — `bootstrap/app.php`

> ⚠️ Laravel 12 removed `Http/Kernel.php`. Use this approach.

```php
use App\Acl\Middleware\CheckPermission;
use App\Acl\Middleware\CheckRole;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(...)
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->alias([
            'permission' => CheckPermission::class,
            'role'       => CheckRole::class,
        ]);

    })
    ->withExceptions(...)
    ->create();
```

### Laravel 10 / 11 — `app/Http/Kernel.php`

```php
protected $middlewareAliases = [
    // ... existing ...
    'permission' => \App\Acl\Middleware\CheckPermission::class,
    'role'       => \App\Acl\Middleware\CheckRole::class,
];
```

---

## 5. Usage — Blade

### Basic permission check

```blade
@permission('employee.create')
    <a href="{{ route('employees.create') }}">+ Add Employee</a>
@endpermission
```

### Check any of multiple permissions

```blade
@anypermission(['employee.edit', 'employee.delete'])
    <div class="actions">...</div>
@endanypermission
```

### Role-based display

```blade
@role('admin')
    <div class="admin-tools">...</div>
@endrole

@anyrole(['admin', 'hr-manager'])
    <div class="hr-tools">...</div>
@endanyrole
```

### Inline PHP check

```blade
@if(auth()->user()->hasPermission('employee.mail'))
    <button>Send Mail</button>
@endif
```

### Pass permissions to JavaScript

```blade
<script>
    window.acl = {
        permissions: @json(auth()->user()->getPermissionSlugs()),
    };
</script>
```

---

## 6. Usage — Controllers

```php
class EmployeeController extends Controller
{
    public function index()
    {
        // No check needed if middleware handles it on the route
        return view('employees.index');
    }

    public function create()
    {
        // Throws 403 if user lacks permission
        $this->authorizePermission('employee.create');
        return view('employees.create');
    }

    public function destroy(int $id)
    {
        $this->authorizePermission('employee.delete');
        // ...
    }

    public function bulkAction()
    {
        // Require any one of these
        $this->authorizeAnyPermission(['employee.edit', 'employee.delete']);
        // ...
    }

    public function sensitiveAction()
    {
        // Require ALL of these
        $this->authorizeAllPermissions(['employee.edit', 'employee.delete']);
        // ...
    }

    public function conditionalAction()
    {
        // Non-throwing check (returns bool)
        if ($this->userCan('employee.mail')) {
            // do something
        }
    }
}
```

---

## 7. Usage — Route Middleware

```php
// Single permission
Route::get('/employees', [EmployeeController::class, 'index'])
    ->middleware('permission:employee.list');

// Multiple permissions (ANY one grants access)
Route::get('/employees/bulk', [EmployeeController::class, 'bulk'])
    ->middleware('permission:employee.edit,employee.delete');

// Role-based
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware('role:admin');

// Multiple roles (ANY)
Route::get('/reports', [ReportController::class, 'index'])
    ->middleware('role:admin,hr-manager');

// Group routes
Route::middleware('permission:employee.list')->group(function () {
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::get('/employees/{id}', [EmployeeController::class, 'show']);
});

// Resource with per-action middleware
Route::resource('employees', EmployeeController::class)
    ->middleware([
        'index'   => 'permission:employee.list',
        'create'  => 'permission:employee.create',
        'store'   => 'permission:employee.create',
        'edit'    => 'permission:employee.edit',
        'update'  => 'permission:employee.edit',
        'destroy' => 'permission:employee.delete',
    ]);
```

---

## 8. Usage — API (React / Vue SPA)

### Sidebar Menu API

```
GET /api/acl/menus
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "menus": [
      {
        "id": 1,
        "name": "Employees",
        "slug": "employees",
        "icon": "users",
        "route_name": null,
        "url": null,
        "is_dropdown": true,
        "children": [
          {
            "id": 2,
            "name": "Employee List",
            "slug": "employees.list",
            "icon": "list",
            "route_name": "employees.index",
            "url": "https://app.test/employees",
            "is_dropdown": false,
            "children": []
          },
          {
            "id": 3,
            "name": "Add Employee",
            "slug": "employees.create",
            "icon": "plus-circle",
            "route_name": "employees.create",
            "url": "https://app.test/employees/create",
            "is_dropdown": false,
            "children": []
          }
        ]
      }
    ],
    "permissions": [
      "employee.list",
      "employee.create",
      "employee.edit",
      "employee.mail"
    ]
  }
}
```

### Header Menu API

```
GET /api/acl/header-menus
Authorization: Bearer {token}
```

Same response shape, but filtered to `type = header` menus.

---

### React Example

```jsx
// hooks/useAcl.js
import { useState, useEffect, createContext, useContext } from 'react';
import axios from 'axios';

const AclContext = createContext(null);

export function AclProvider({ children }) {
  const [permissions, setPermissions] = useState([]);
  const [sidebarMenus, setSidebarMenus] = useState([]);
  const [headerMenus, setHeaderMenus]   = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      axios.get('/api/acl/menus'),
      axios.get('/api/acl/header-menus'),
    ]).then(([sidebar, header]) => {
      setPermissions(sidebar.data.data.permissions);
      setSidebarMenus(sidebar.data.data.menus);
      setHeaderMenus(header.data.data.menus);
    }).finally(() => setLoading(false));
  }, []);

  const can = (slug) => permissions.includes(slug);
  const canAny = (slugs) => slugs.some(s => permissions.includes(s));

  return (
    <AclContext.Provider value={{ can, canAny, sidebarMenus, headerMenus, loading }}>
      {children}
    </AclContext.Provider>
  );
}

export const useAcl = () => useContext(AclContext);
```

```jsx
// Usage in any component
import { useAcl } from '@/hooks/useAcl';

export function EmployeeActions({ employeeId }) {
  const { can } = useAcl();

  return (
    <div>
      {can('employee.edit') && (
        <button onClick={() => handleEdit(employeeId)}>Edit</button>
      )}
      {can('employee.delete') && (
        <button onClick={() => handleDelete(employeeId)}>Delete</button>
      )}
      {can('employee.mail') && (
        <button onClick={() => handleMail(employeeId)}>Send Mail</button>
      )}
    </div>
  );
}
```

```jsx
// Sidebar component
import { useAcl } from '@/hooks/useAcl';
import { Link } from 'react-router-dom';

function SidebarMenu({ items }) {
  return (
    <ul>
      {items.map(item => (
        <li key={item.id}>
          {item.is_dropdown ? (
            <>
              <span>{item.icon && <Icon name={item.icon} />} {item.name}</span>
              <SidebarMenu items={item.children} />
            </>
          ) : (
            <Link to={item.url}>
              {item.icon && <Icon name={item.icon} />} {item.name}
            </Link>
          )}
        </li>
      ))}
    </ul>
  );
}

export function Sidebar() {
  const { sidebarMenus, loading } = useAcl();
  if (loading) return <div>Loading...</div>;
  return <SidebarMenu items={sidebarMenus} />;
}
```

---

## 9. Permission Resolution Priority

```
┌──────────────────────────────────────────────────────────┐
│                   User requests access                    │
└─────────────────────────┬────────────────────────────────┘
                           │
              ┌────────────▼─────────────┐
              │ Direct override exists?   │
              └────────────┬─────────────┘
                  YES      │      NO
         ┌─────────────────┘
         ▼
  override.allow?              ┌──────────────────────────┐
  true → ALLOW                 │ User has role with this  │
  false → DENY                 │ permission slug?         │
                               └────────────┬─────────────┘
                                   YES      │      NO
                                            │
                                    ALLOW   │    DENY
```

**This means:**
- An admin can explicitly **deny** a permission to a user even if their role allows it (set `allow = false` in `user_permissions`)
- An admin can explicitly **grant** a permission to a user even if their role doesn't have it (set `allow = true` in `user_permissions`)

---

## 10. Admin: Granting & Revoking Permissions

### From a controller or admin panel:

```php
$user = User::find($userId);

// ── Roles ──────────────────────────────────────────────────────────────────

// Assign a role
$user->assignRole('hr-manager');

// Revoke a role
$user->revokeRole('hr-manager');

// Sync roles (replaces all existing)
$user->syncRoles(['admin', 'hr-manager']);

// ── Direct permission overrides ────────────────────────────────────────────

// Grant override (even if role doesn't have it)
$user->grantPermission(
    'employee.delete',
    reason: 'Temporary access for Q4 review',
    grantedBy: auth()->id()
);

// Deny override (even if role allows it)
$user->denyPermission(
    'employee.delete',
    reason: 'Under disciplinary review',
    grantedBy: auth()->id()
);

// Remove override (falls back to role-based)
$user->revokeDirectPermission('employee.delete');

// ── Role permissions (affects all users in the role) ───────────────────────

$role = Role::where('slug', 'hr-manager')->first();

// Add permissions
$role->permissions()->syncWithoutDetaching(
    Permission::whereIn('slug', ['employee.delete'])->pluck('id')
);

// Remove permissions
$role->permissions()->detach(
    Permission::where('slug', 'employee.delete')->first()->id
);

// IMPORTANT: Clear cache for all affected users after role permission changes
app(\App\Acl\Services\PermissionResolver::class)->clearCacheForRole($role);
```

### Cache invalidation is automatic when you use model methods:

| Action | Cache auto-cleared? |
|---|---|
| `$user->assignRole(...)` | ✅ Yes (user cache) |
| `$user->revokeRole(...)` | ✅ Yes (user cache) |
| `$user->syncRoles(...)` | ✅ Yes (user cache) |
| `$user->grantPermission(...)` | ✅ Yes (user cache) |
| `$user->denyPermission(...)` | ✅ Yes (user cache) |
| `$user->revokeDirectPermission(...)` | ✅ Yes (user cache) |
| Direct `role->permissions()->sync()` | ❌ Must call `clearCacheForRole($role)` |

---

## 11. Caching Strategy

### How it works

All permission resolution results are cached per user:

```
Cache key: acl:user:{id}:permissions  → resolved permissions (overrides + role slugs)
Cache key: acl:user:{id}:roles        → user's roles
Cache key: acl:all_permissions        → global permission table snapshot
Cache key: acl:user:{id}:menu:sidebar → built sidebar tree
Cache key: acl:user:{id}:menu:header  → built header tree
```

Default TTL: **1 hour** (configurable via `ACL_CACHE_TTL`).

### Recommended driver: Redis

```env
CACHE_DRIVER=redis
ACL_CACHE_TAGS=true   # enables tag-based flush
```

With tags enabled, you can flush ALL ACL cache at once:
```php
Cache::tags(['acl'])->flush();
```

Without tags (file/database cache):
```php
app(\App\Acl\Services\PermissionResolver::class)->clearGlobalCache();
// Then clear per-user caches as needed
```

### When to clear cache

| Event | Clear |
|---|---|
| User role assigned/revoked | `$user->clearPermissionCache()` |
| User direct permission changed | `$user->clearPermissionCache()` |
| Role permissions changed | `$resolver->clearCacheForRole($role)` |
| New permission added to DB | `$resolver->clearGlobalCache()` |
| Permission deactivated | `$resolver->clearGlobalCache()` |
| Menu structure changed | `$menuBuilder->clearMenuCacheForUser($userId)` |

---

## 12. Scaling Best Practices

### 1. Use Redis with tag-based cache invalidation
```env
CACHE_DRIVER=redis
ACL_CACHE_TAGS=true
```

### 2. Increase TTL for stable permission structures
```env
ACL_CACHE_TTL=86400  # 24 hours if permissions rarely change
```

### 3. Pre-warm cache on login
```php
// In your LoginController or AuthenticatedSessionController:
public function authenticated(Request $request, $user)
{
    // Pre-load and cache permissions immediately on login
    app(PermissionResolver::class)->getResolvedPermissions($user);
    app(MenuBuilderService::class)->getSidebarMenu($user);
}
```

### 4. Broadcast cache invalidation in multi-server setups
If running multiple app servers, use Redis pub/sub or Laravel Broadcasting to invalidate cache on all nodes when permissions change.

### 5. Eager load relationships to avoid N+1
The `MenuBuilderService` already eager-loads children and permissions. In custom queries, always eager-load:
```php
$users = User::with(['roles.permissions', 'directPermissions'])->get();
```

### 6. Audit log permission changes
The `user_permissions` table has `reason` and `granted_by` columns. Add an observer or model event to log all changes to an audit table.

### 7. Permission slug naming convention
Use `module.action` format consistently:
```
employee.list      ✅
employee.create    ✅
employee-create    ❌
employeeCreate     ❌
```

---

## 13. File Structure Reference

```
app/Acl/
├── Models/
│   ├── Module.php
│   ├── Menu.php
│   ├── Permission.php
│   └── Role.php
├── Traits/
│   ├── HasPermissions.php          ← add to User model
│   └── AuthorizesPermissions.php   ← add to base Controller
├── Middleware/
│   ├── CheckPermission.php         ← 'permission:slug' middleware
│   └── CheckRole.php               ← 'role:slug' middleware
├── Services/
│   ├── PermissionResolver.php      ← core resolution + caching
│   └── MenuBuilderService.php      ← menu tree builder
├── Http/
│   ├── Controllers/Api/
│   │   ├── SidebarMenuController.php
│   │   └── HeaderMenuController.php
│   └── Resources/
│       └── MenuResource.php
└── Providers/
    └── AclServiceProvider.php      ← registers everything

config/
└── acl.php

database/migrations/
├── 2024_01_01_000001_create_modules_table.php
├── 2024_01_01_000002_create_menus_table.php
├── 2024_01_01_000003_create_permissions_table.php
├── 2024_01_01_000004_create_roles_table.php
├── 2024_01_01_000005_create_role_permission_table.php
├── 2024_01_01_000006_create_user_roles_table.php
└── 2024_01_01_000007_create_user_permissions_table.php

database/seeders/
└── EmployeeModuleSeeder.php

routes/
└── api.php (loaded automatically by AclServiceProvider)

stubs/
├── bootstrap_app_laravel12.php     ← Laravel 12 middleware registration
├── kernel_laravel10_11.php         ← Laravel 10/11 middleware registration
├── User.php                        ← example User model
├── Controller.php                  ← example base Controller
├── EmployeeController.php          ← example resource controller
└── employees_blade_example.blade.php
```

---

## Quick Reference Card

```php
// ── User checks ───────────────────────────────────────────────────────────
$user->hasPermission('employee.create')           // bool
$user->hasAnyPermission(['a', 'b'])               // bool
$user->hasAllPermissions(['a', 'b'])              // bool
$user->hasRole('admin')                           // bool
$user->hasAnyRole(['admin', 'hr-manager'])        // bool
$user->getPermissionSlugs()                       // string[]

// ── Role management ───────────────────────────────────────────────────────
$user->assignRole('hr-manager')
$user->revokeRole('hr-manager')
$user->syncRoles(['admin', 'hr-manager'])

// ── Override management ───────────────────────────────────────────────────
$user->grantPermission('employee.delete', reason: '...', grantedBy: 1)
$user->denyPermission('employee.delete',  reason: '...', grantedBy: 1)
$user->revokeDirectPermission('employee.delete')
$user->clearPermissionCache()

// ── Controller ────────────────────────────────────────────────────────────
$this->authorizePermission('employee.create')
$this->authorizeAnyPermission(['a', 'b'])
$this->authorizeAllPermissions(['a', 'b'])
$this->userCan('employee.mail')               // bool, no abort

// ── Route middleware ──────────────────────────────────────────────────────
->middleware('permission:employee.edit')
->middleware('permission:employee.edit,employee.create')  // ANY
->middleware('role:admin')
->middleware('role:admin,hr-manager')                     // ANY

// ── Blade directives ──────────────────────────────────────────────────────
@permission('slug') ... @endpermission
@anypermission(['a','b']) ... @endanypermission
@role('slug') ... @endrole
@anyrole(['a','b']) ... @endanyrole

// ── API endpoints ─────────────────────────────────────────────────────────
GET /api/acl/menus          → sidebar menu + permissions[]
GET /api/acl/header-menus   → header menu
```
