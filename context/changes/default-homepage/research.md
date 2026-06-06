---
date: 2026-06-06T08:30:00Z
researcher: claude-sonnet-4-6
git_commit: 0c2d821ef9a137fa99838ae60abf15ece0043db7
branch: main
repository: houseminder
topic: "Default homepage — root URL behaviour for guests vs authenticated users"
tags: [research, routing, auth, welcome, dashboard, livewire, volt]
status: complete
last_updated: 2026-06-06
last_updated_by: claude-sonnet-4-6
---

# Research: Default homepage — root URL behaviour

**Date**: 2026-06-06  
**Git Commit**: 0c2d821ef9a137fa99838ae60abf15ece0043db7  
**Branch**: main  
**Repository**: houseminder

## Research Question

What does the app show at `/` today for guests vs authenticated users, and what changes are needed to give the root URL smart redirect behaviour?

## Summary

The app has a classic Breeze welcome page at `/` that renders for **all visitors** — guests and authenticated users alike. Authenticated users are not redirected to `/dashboard`; they just see the welcome page with a "Dashboard" nav link. The fix is to make `/` smart: redirect authenticated users to `/dashboard` automatically, and continue showing the welcome view to guests.

The implementation is minimal — one route change and no new components.

---

## Detailed Findings

### Current root route

**File:** `routes/web.php:8`

```php
Route::view('/', 'welcome');
```

- No middleware. All visitors (guest and authenticated) receive the `welcome` Blade view.
- The welcome page (`resources/views/welcome.blade.php`) uses a lightweight nav component (`livewire:welcome.navigation`) that shows **Login / Register** for guests or a **Dashboard** link for authenticated users — but performs no redirect.

### Dashboard route

**File:** `routes/web.php:10-12`

```php
Volt::route('dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
```

- Protected by `auth` + `verified` middleware.
- Volt component: `pages.dashboard` (`resources/views/livewire/pages/dashboard.blade.php`).

### Post-login redirect

**File:** `resources/views/livewire/pages/auth/login.blade.php:23`

```php
$this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
```

- Uses `redirectIntended()` — respects the originally-intended URL, falls back to `/dashboard`.

**File:** `resources/views/livewire/pages/auth/register.blade.php:50`

```php
$this->redirect(route('dashboard', absolute: false), navigate: true);
```

- Registration always redirects straight to `/dashboard`.

### Logout redirect

**File:** `resources/views/livewire/layout/navigation.blade.php:17`

```php
$this->redirect('/', navigate: true);
```

- After logout, users are sent to `/` (the welcome page). This is correct — no change needed here.

### Middleware structure

- No `RouteServiceProvider` with a `HOME` constant exists — redirects are explicit `route('dashboard')` calls.
- `bootstrap/app.php` has an empty `withMiddleware` block — no custom redirect middleware defined.
- Default Laravel `Authenticate` middleware redirects unauthenticated users to `/login`.
- `RedirectIfAuthenticated` middleware (Breeze default) redirects guests-only routes for authenticated users to `/` — this is relevant for the `/login` and `/register` guest-guarded routes.

### Layout structure

| Layout | File | Used by |
|---|---|---|
| `layouts.app` | `resources/views/layouts/app.blade.php` | All authenticated Volt pages (dashboard, appliances) |
| `layouts.guest` | `resources/views/layouts/guest.blade.php` | Auth pages (login, register, etc.) |
| None (standalone) | `resources/views/welcome.blade.php` | The `/` welcome page only |

---

## Code References

- `routes/web.php:8` — Root route (`Route::view('/', 'welcome')`) — the single change point
- `routes/web.php:10-12` — Dashboard Volt route with auth middleware
- `resources/views/welcome.blade.php` — Welcome page (no auth check, no redirect)
- `resources/views/livewire/welcome/navigation.blade.php` — Nav shown on welcome page; shows Dashboard link for authenticated users
- `resources/views/livewire/pages/auth/login.blade.php:23` — Post-login `redirectIntended()` call
- `resources/views/livewire/pages/auth/register.blade.php:50` — Post-registration redirect to dashboard
- `resources/views/livewire/layout/navigation.blade.php:17` — Post-logout redirect to `/`
- `app/Http/Middleware/` — No custom root-redirect middleware exists; one would need to be added, or the route closure approach used

---

## Architecture Insights

**Simplest implementation — closure or redirect route:**

Replace `Route::view('/', 'welcome')` with a closure that redirects authenticated users:

```php
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return view('welcome');
});
```

This keeps everything in one place and requires zero new files.

**Alternative — middleware approach:**

Create a `RedirectAuthenticatedHome` middleware and attach it to the `/` route. More reusable if more routes ever need this behaviour, but overkill for a single route.

**No changes needed to:**
- The welcome Blade view itself
- The dashboard Volt component
- The login/register post-redirect logic
- The logout redirect (stays at `/`, which will serve welcome for guests — correct)

**Edge case — email-unverified users:**
If an authenticated but unverified user hits `/`, the closure `auth()->check()` returns `true` and they'd be redirected to `/dashboard`, which then bounces them to the email verification page via the `verified` middleware. This is acceptable — unverified users end up on the verify-email page rather than the welcome page, which is the better UX.

---

## Historical Context

No prior changes in `context/changes/` or `context/archive/` address the root URL behaviour directly. The Breeze scaffold was set up as part of the initial project bootstrapping.

The dashboard Volt component was extensively developed in the archived `dashboard-tasks-and-mark-done` change and the testing rollout phases, but the root route itself was never touched.

---

## Open Questions

1. **Marketing / landing page intent?** The welcome page currently serves as both the app entry point and a minimal marketing page. If a richer landing page is ever planned for unauthenticated visitors, the route change here should accommodate that (`return view('welcome')` already does).
2. **`auth()->check()` vs `auth()->user()`?** Both are equivalent here; `auth()->check()` is more idiomatic for a boolean guard.
3. **Livewire navigate compatibility?** The closure returns a standard Laravel `redirect()` response. This works correctly with Livewire's `navigate` wire — Livewire SPA navigation intercepts it client-side and performs the redirect without a full page reload.
