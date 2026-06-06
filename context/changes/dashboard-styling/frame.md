# Frame Brief: Dashboard styling regression — stale CSS build

> Framing step before /10x-plan. This document captures what is *actually*
> at issue, separated from what was initially assumed.

## Reported Observation

Dashboard elements that previously had color-coding (overdue = red, upcoming = yellow/gray)
and spacing between boxes now render as grey/black with no gaps. Both colors AND spacing
are missing simultaneously. User confirmed seeing it work previously.

## Initial Framing (preserved)

- **User's stated cause or approach**: Styling was lost/regressed sometime today or yesterday
- **User's proposed direction**: Restore the styling to its previous working state
- **Pre-dispatch narrowing**: User confirmed they saw colors and spacing working themselves (not inferred); no obvious trigger identified; DevTools not yet checked

## Dimension Map

The observation could originate at any of these dimensions:

1. **Dynamic class construction purged by JIT** — Classes assembled at runtime (e.g. `'bg-' . $color`) never appear in the CSS bundle
2. **Template regression** — A recent commit removed or changed color/spacing classes from Blade/Livewire templates
3. **Stale CSS build** — CSS bundle predates template changes; compiled before color/spacing classes were added  ← **initial framing lands here**
4. **Tailwind `content` config change** — Paths no longer cover relevant templates; classes purged
5. **Asset import removed** — Stylesheet not loaded in layout at all

## Hypothesis Investigation

| Hypothesis | Evidence | Verdict |
| --- | --- | --- |
| Dynamic class construction (H1) | All color/spacing classes in dashboard.blade.php and appliances/index.blade.php are complete static strings — no string concatenation, no variable interpolation. Full inventory confirmed. | NONE |
| Template regression (H2) | Git log for 3 days: zero removal of color or spacing classes from any template. All classes (`text-red-700`, `border-yellow-200`, `space-y-2`, etc.) are bitwise identical from creation to HEAD. No CSS or Tailwind config changes. | NONE |
| Stale CSS build (H3) | `public/build/assets/app-S81kRYzA.css` — built **May 30 22:06**. `dashboard.blade.php` — last modified **June 5 23:22** (6 days later, adding color/spacing classes). Grep confirms: `text-red-*`, `border-red-*`, `text-yellow-*`, `border-yellow-*` are **absent from the compiled CSS**. Only `text-gray-*` colors present. | STRONG |
| Tailwind content config change (H4) | `tailwind.config.js` content array includes `./resources/views/**/*.blade.php` — covers all dashboard templates. No changes to config in 3 days. | NONE |
| Asset import removed (H5) | `resources/views/layouts/app.blade.php:15` has `@vite(['resources/css/app.css', 'resources/js/app.js'])` — present and correct. | NONE |

## Narrowing Signals

- User saw it working themselves → confirms it rendered correctly at some point, not a never-worked case
- No obvious trigger identified → consistent with Vite dev server stopping between sessions (not a deliberate code change)
- Both colors AND spacing missing simultaneously → strongly points to a global CSS miss, not a targeted template edit
- Git history clean → rules out template regression completely

## Cross-System Convention

Tailwind JIT compiles only the classes it finds in the scanned content at build time. Classes added to templates *after* the last `npm run build` are invisible to the bundle. Vite dev server (`npm run dev`) compiles on-the-fly and masks this — the app looks correct during a dev session but breaks when the dev server is stopped and the app falls back to `public/build`.

This is the standard "worked in dev, broken without dev server" pattern for Tailwind projects that use the production bundle in development without keeping the build current.

## Reframed (or Confirmed) Problem Statement

> **The actual problem to plan around is**: The production CSS bundle (`public/build/assets/app-S81kRYzA.css`) was compiled on May 30, before the dashboard color and spacing classes were added on June 5 — so those classes have never existed in the compiled output.

The styling was not *lost* — it was added to templates but never compiled into the CSS bundle. The visual appeared to work because the Vite dev server compiled it on-the-fly; stopping the dev server exposed the stale bundle. The fix is a one-step rebuild, plus optionally adding `npm run build` to the dev workflow so the production bundle stays current.

## Confidence

- **HIGH** — strong direct evidence (file timestamps + grep confirming absent classes) + matches known Tailwind/Vite convention exactly + cross-system check (dev server explains the "was working" observation) + all other hypotheses ruled out by code inspection.

## What Changes for /10x-plan

The plan should be: rebuild the CSS bundle (`npm run build`) and verify the colors and spacing render. If the team relies on `npm run dev` for local development, the plan may also add a note or workflow reminder to keep the production build current (or configure dev to always use Vite dev server, not the stale build). No template code changes needed.

## References

- Compiled CSS: `public/build/assets/app-S81kRYzA.css` (built May 30, 46 KB)
- Dashboard template: `resources/views/livewire/pages/dashboard.blade.php` (modified June 5)
- Tailwind config: `tailwind.config.js:6-10`
- Layout import: `resources/views/layouts/app.blade.php:15`
- Investigation tasks: H1 (dynamic classes), H2 (git history), H3 (build/config)
