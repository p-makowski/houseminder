<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Quality-Gates Wiring Implementation Plan

- **Plan**: context/changes/testing-quality-gates-wiring/plan.md
- **Mode**: Deep
- **Date**: 2026-06-10
- **Verdict**: REVISE
- **Findings**: 1 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | PASS |
| Plan Completeness | FAIL |

## Grounding

4/4 paths ✓ (.claude/settings.local.json, composer.json, Dockerfile, AGENTS.md — .github/ absence confirmed as documented), symbols ✗ (--paths= flag invalid — see F1), brief↔plan ✓

## Findings

### F1 — PHPStan hook uses `--paths=` which is not a valid CLI flag

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 3 — PostToolUse Hook, step 3 of the command shape
- **Detail**: The plan documents the hook's PHPStan invocation as `./vendor/bin/phpstan analyse --paths="$FILE" --no-progress --memory-limit=512M`. Verified via `./vendor/bin/phpstan analyse --help`: `paths` is a positional argument, not a named option. There is no `--paths` flag. PHPStan exits with "Option `--paths` does not exist" and a non-zero code. As written, the hook would block the agent loop on every PHP file edit — even perfectly valid ones — making the gate permanently broken rather than selectively enforcing quality.
- **Fix**: Replace `--paths="$FILE"` with a bare positional argument: `./vendor/bin/phpstan analyse "$FILE" --no-progress --memory-limit=512M`
- **Decision**: FIXED — positional arg `"$FILE"` replaces `--paths="$FILE"`

### F2 — Phase 3 hook command shape described in prose, not as a shell string

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Completeness
- **Location**: Phase 3 — Changes Required, §1
- **Detail**: Phase 3 describes the hook in five numbered behavioral steps but stops short of providing the actual shell string that goes in `settings.local.json`. Two specific ambiguities will force the implementer to guess: (1) Exit-code ORing — "OR of both commands" doesn't specify the shell pattern; using `&&` (short-circuit) means Pint is silently skipped when PHPStan fails; (2) The non-.php bailout combined with the file-path extraction needs careful single-line shell handling. The existing hook in settings.local.json shows the right shape but uses short-circuit logic, which is wrong for the two-gate case here.
- **Fix A ⭐ Recommended**: Add an exact shell command to the plan's "Contract" block for Phase 3. Proposed command (semicolon-separated for JSON string): `FILE=$(jq -r '.tool_input.file_path'); [[ "$FILE" == *.php ]] || exit 0; ./vendor/bin/phpstan analyse "$FILE" --no-progress --memory-limit=512M; STAN=$?; ./vendor/bin/pint --test "$FILE"; PINT=$?; exit $((STAN | PINT))`. Strength: Removes all implementer guesswork; combines F1's fix; mirrors the level of specificity in Phase 1 and 2 contracts. Tradeoff: Two lines more in the plan. Confidence: HIGH — pattern mirrors the existing hook and correctly ORs the two exit codes. Blind spot: Confirm semicolons inside a JSON string value work in the hooks runner (they do in standard shell invocation).
- **Fix B**: Leave the prose and add an explicit callout: "implement as sequential: run both commands regardless of individual exit, then OR the codes." Strength: Shorter edit; lets the implementer choose the shell idiom. Tradeoff: Still leaves the exact syntax to the implementer; F1 must also be fixed separately. Confidence: MED. Blind spot: None.
- **Decision**: FIXED via Fix B — prose callout added to step 5: "do NOT short-circuit with &&; capture both exit codes separately and combine"

### F3 — PHP 8.5 developer machine risk has no mitigation path in Phase 1

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 1 — composer.json change, plan-brief "Open Risks"
- **Detail**: The brief correctly flags "bumping require.php to ^8.5 breaks composer install on 8.3/8.4" but neither the brief nor Phase 1 provides a remediation step. Any developer who pulls the Phase 1 commit and runs `composer install` on PHP 8.3/8.4 gets a hard failure with no guidance. Separately verified: `dunglas/frankenphp:1-php8.5` exists on Docker Hub (last updated 2026-06-07), so the Dockerfile change is safe.
- **Fix**: In the AGENTS.md change (Phase 1, §3), add one sentence: "Local PHP must be 8.5+; update via your version manager before running composer install."
- **Decision**: FIXED — PHP upgrade note added to Phase 1 AGENTS.md contract
