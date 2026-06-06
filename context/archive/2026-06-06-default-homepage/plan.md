# Default Homepage — Implementation Plan

## Overview

Replace the static Laravel welcome page at `/` with a smart redirect: guests go to `/login`, authenticated users go to `/dashboard`. Delete the now-unused welcome view and navigation component. Fix the logout redirect to go directly to `/login` rather than bouncing through `/`.

## Current State Analysis

- `routes/web.php:8`: `Route::view('/', 'welcome')` — serves the welcome Blade view to all visitors regardless of auth state.
- `resources/views/welcome.blade.php` — standard Breeze welcome page; shows Login/Register for guests, a "Dashboard" link for authenticated users. Nobody should see this after the change.
- `resources/views/livewire/welcome/navigation.blade.php` — lightweight nav shown only on the welcome page. Unused after deletion.
- `resources/views/livewire/layout/navigation.blade.php:17` — logout redirects to `'/'`. After the root redirect is in place, this would produce a double redirect (`/` → `/login`). Fix it to redirect to `/login` directly.
- No `RouteServiceProvider::HOME` constant exists — redirects are explicit `route(...)` calls throughout the app.
- `RedirectIfAuthenticated` middleware is not customized; Laravel 11 built-in redirects authenticated users on guest-guarded routes to `route('dashboard')`.

## Desired End State

- `GET /` unauthenticated → 302 to `/login`
- `GET /` authenticated → 302 to `/dashboard`
- Logout → 302 to `/login` (direct, no intermediate `/`)
- `welcome.blade.php` and `livewire/welcome/navigation.blade.php` are deleted

Verification: visiting `localhost:8000` as a guest lands directly on the login page. Visiting as an authenticated user lands on the dashboard. Logging out lands on the login page.

### Key Discoveries

- Only `routes/web.php` references `welcome` — no other files need updating when the view is deleted (`grep -r "welcome" routes/ app/ resources/views/ --include="*.php" -l` returned only `routes/web.php`).
- The `livewire:welcome.navigation` component is used solely inside `welcome.blade.php` — safe to delete alongside it.
- `auth()->check()` is the correct guard here — purely a boolean, no household or verification state involved. Unverified authenticated users will be redirected to `/dashboard` which bounces them to the verify-email page via the `verified` middleware — correct UX.

## What We're NOT Doing

- Changing login/register post-redirect logic (already correct: both land on `/dashboard`).
- Modifying the dashboard Volt component or its middleware.
- Adding a landing/marketing page for guests — `/login` is the intended guest entry point.
- Creating a custom `RedirectIfAuthenticated` middleware — the Laravel built-in already handles guest routes correctly.

---

## Phase 1: Smart Redirect + Cleanup + Tests

### Overview

Replace the root route with a redirect closure, fix the logout redirect, delete the two now-dead files, and add two regression tests.

### Changes Required

#### 1. Root route — replace static view with redirect closure

**File**: `routes/web.php`

**Intent**: Replace `Route::view('/', 'welcome')` with a `Route::get` closure that redirects authenticated users to the dashboard and guests to the login page.

**Contract**: The new route handles `GET /` with no middleware. Use `auth()->check()` as the guard. Return `redirect()->route('dashboard')` for authenticated users and `redirect()->route('login')` for guests. The existing named routes `dashboard` and `login` are already defined in `routes/web.php` and `routes/auth.php` respectively.

#### 2. Logout redirect — direct to login instead of root

**File**: `resources/views/livewire/layout/navigation.blade.php`

**Intent**: After logout, send the user directly to `/login` instead of `/`. With the root redirect in place, going to `/` would produce an unnecessary extra roundtrip (`/` → `/login`). Fix the source to avoid the chain.

**Contract**: Line 17 currently reads `$this->redirect('/', navigate: true)`. Change the redirect target to `route('login', absolute: false)` and drop `navigate: true` — after logout a full page redirect is preferable to SPA navigation to ensure client-side component state is cleared.

#### 3. Delete welcome view

**File**: `resources/views/welcome.blade.php`

**Intent**: Remove the file — it is no longer reachable and its content (the Breeze marketing page) is no longer wanted. No other file references it after the route change.

#### 4. Delete welcome navigation component

**File**: `resources/views/livewire/welcome/navigation.blade.php`

**Intent**: Remove the file — it was used only inside `welcome.blade.php`, which is being deleted.

#### 5. Regression test class

**File**: `tests/Feature/RootRedirectTest.php` (new)

**Intent**: Assert both redirect paths so a future route change can't silently break the entry-point flow.

**Contract**: Extends `Tests\TestCase`, uses `RefreshDatabase`. Two test methods:
- `test_guest_is_redirected_to_login` — `$this->get('/')->assertRedirect(route('login'))`.
- `test_authenticated_user_is_redirected_to_dashboard` — create a `User` via factory, `actingAs`, `get('/')`, `assertRedirect(route('dashboard'))`.

No base `TestCase` subclass needed — the auth test only requires `User::factory()->create()` inline (no household fixture).

---

### Success Criteria

#### Automated Verification

- New tests pass: `composer test --filter RootRedirectTest`
- PHPStan level 6 clean: `./vendor/bin/phpstan analyse`
- Code style clean: `./vendor/bin/pint --test`
- Full suite passes: `composer test`

#### Manual Verification

- Visit `localhost:8000` as a guest → lands on `/login` directly (no welcome page flash)
- Visit `localhost:8000` while authenticated → lands on `/dashboard` directly
- Log out → lands on `/login` directly (no intermediate redirect through `/`)

**Implementation Note**: After automated verification passes, pause for manual confirmation before the phase-end commit.

---

## Testing Strategy

### Feature Tests

- `tests/Feature/RootRedirectTest.php` — 2 tests covering guest and authenticated redirect paths

### Manual Testing Steps

1. Open a private/incognito window, visit `localhost:8000` — should land on the login page.
2. Log in with a valid account — should land on the dashboard.
3. Log out — should land on the login page.
4. While logged in, navigate to `localhost:8000` directly — should land on the dashboard (no welcome page).

## References

- Research: `context/changes/default-homepage/research.md`
- Root route: `routes/web.php:8`
- Logout redirect: `resources/views/livewire/layout/navigation.blade.php:17`
- Welcome view: `resources/views/welcome.blade.php`
- Welcome nav: `resources/views/livewire/welcome/navigation.blade.php`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Smart Redirect + Cleanup + Tests

#### Automated

- [x] 1.1 New tests pass: `composer test --filter RootRedirectTest` — 49afdac
- [x] 1.2 PHPStan level 6 clean: `./vendor/bin/phpstan analyse` — 49afdac
- [x] 1.3 Code style clean: `./vendor/bin/pint --test` — 49afdac
- [x] 1.4 Full suite passes: `composer test` — 49afdac

#### Manual

- [ ] 1.5 Guest visits localhost:8000 → lands on login page directly
- [ ] 1.6 Authenticated user visits localhost:8000 → lands on dashboard directly
- [ ] 1.7 Logout → lands on login page directly
