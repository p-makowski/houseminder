# Default Homepage — Plan Brief

> Full plan: `context/changes/default-homepage/plan.md`
> Research: `context/changes/default-homepage/research.md`

## What & Why

Replace the static Laravel welcome page at `/` with a smart redirect. No visitor — guest or authenticated — should land on the default Breeze welcome page. Guests go to `/login`; authenticated users go to `/dashboard`.

## Starting Point

`routes/web.php:8` uses `Route::view('/', 'welcome')` with no middleware, serving the Breeze welcome page to everyone. The logout redirect in the navigation component also points to `/`, which would create a double-redirect chain once the root is changed.

## Desired End State

Visiting `localhost:8000` as a guest lands directly on the login page. Visiting while authenticated lands on the dashboard. Logging out lands on the login page. The welcome view and its nav component are deleted — there is no welcome page in the codebase.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Guest destination at `/` | `/login` | User explicitly wants no welcome page — guests should go straight to login | User |
| Auth destination at `/` | `/dashboard` | Consistent with post-login redirect already in place | Research |
| Welcome page fate | Delete both files | No other files reference them; dead code with no future use | Research + User |
| Logout redirect fix | Change to `/login` directly | Avoids unnecessary double-redirect through `/` after the root change | Plan |
| Implementation | Route closure | Single file, zero new components, follows existing route pattern | Research |
| Test coverage | 2-assertion test class | Regression guard consistent with PHPUnit test infrastructure | User |

## Scope

**In scope:**
- Root route replacement (`routes/web.php:8`)
- Logout redirect fix (`layout/navigation.blade.php:17`)
- Delete `welcome.blade.php` and `livewire/welcome/navigation.blade.php`
- Regression tests for both redirect paths

**Out of scope:**
- Login/register post-redirect logic (already correct)
- Marketing/landing page for guests
- Dashboard component or its middleware
- Custom `RedirectIfAuthenticated` middleware

## Architecture / Approach

One closure in `routes/web.php` replaces the static view route. `auth()->check()` is the guard — no household or verification state involved. Unverified authenticated users hit the `verified` middleware on `/dashboard` and land on the verify-email page, which is correct UX. Two file deletions clean up dead code. One new test class with two assertions covers regression.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Smart redirect + cleanup + tests | Working redirect, deleted dead code, regression tests | None significant — search confirms no other references to deleted files |

**Prerequisites:** None  
**Estimated effort:** ~1 session, one phase

## Open Risks & Assumptions

- `auth()->check()` for the guard is correct — no edge cases beyond the unverified user path (which resolves correctly via dashboard's `verified` middleware).
- No other files reference `welcome.blade.php` or `livewire/welcome/navigation.blade.php` — confirmed by grep.
