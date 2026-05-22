# Safe-Mode Hardening — Design

**Date:** 2026-05-21
**Plugin:** `leastudios-snippets`
**Status:** Approved design, pending implementation plan

## Motivation

`leastudios-snippets` executes administrator-supplied PHP via `eval()`. Its safety net —
"safe mode" — is narrower than it appears and has several rough edges:

1. **Containment gap.** `Snippet_Executor::execute_php()` wraps only the `eval()` call in a
   custom error handler + `try/catch (\Throwable)`. Most snippets do their real work by
   *registering hooks* (`add_action('wp_head', …)`); that deferred code runs later, outside
   any containment. A fatal in a deferred callback — or any uncatchable fatal (out-of-memory,
   execution timeout, `exit()`) — is not caught, so the offending snippet stays active and
   re-breaks the site on every request.
2. **Over-aggressive deactivation.** The in-`eval()` error handler treats *every* error level
   as fatal. A snippet emitting a mere `E_NOTICE` or `E_DEPRECATED` is auto-deactivated,
   contradicting the documented "fatal errors are auto-deactivated" behavior.
3. **Silent data loss.** `Snippet_Editor::save()` silently discards snippet code larger than
   `MAX_CODE_BYTES` (256 KB) with no feedback to the user.
4. **No code-modification gate.** Snippets are an equivalent code-execution surface to the
   theme/plugin file editors, but the plugin ignores `DISALLOW_FILE_MODS` /
   `DISALLOW_FILE_EDIT`, which sites set specifically to lock down code changes.
5. **Thin tests.** Only `Condition_Checker` has coverage. The execution engine, safe mode,
   library install, and capability mapping — including the security-critical paths — are
   untested.

This pass closes all five, with overall plugin quality as the deciding criterion.

## Decisions

Resolved during brainstorming:

| Decision | Choice |
|---|---|
| Gate snippet editing on `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT`? | **Yes** — include it. |
| Non-fatal warnings during execution? | **Record, don't deactivate** — capture them and show a non-blocking admin notice. |
| Test verification? | **Install the WP test library** and run the suite test-first. |
| Fatal-containment approach? | **Shutdown handler + execution marker**, and **extract a dedicated `Execution/Safe_Mode` class**. |

## Design

### 1. New component — `Execution/Safe_Mode`

A dedicated class owning all error-containment state and logic, extracted out of the
~540-line `Snippet_Executor`. This makes the exact code being hardened independently
unit-testable and shrinks the executor to a single responsibility (querying, grouping,
hook registration, dispatch).

Public surface:

- `init(): void` — registers the shutdown handler once, via `register_shutdown_function()`
  (runs regardless of WordPress hook state after a fatal).
- `get_disabled_ids(): array<int,int>` / `is_disabled(int $snippet_id): bool` — the
  safe-mode list, backed by the existing `leastudios_snippets_safe_mode` option. Replaces
  `Snippet_Executor::get_safe_mode_ids()`.
- `begin(int $snippet_id): ?int` — records `$snippet_id` as the currently-executing snippet,
  returns the previous marker value.
- `end(?int $previous): void` — restores the marker to `$previous`. Save/restore (rather
  than clear-to-null) keeps nesting correct when one snippet triggers another.
- `deactivate(int $snippet_id, string $message): void` — sets `META_ACTIVE` → `'0'`,
  appends the ID to the `leastudios_snippets_safe_mode` option, stores `$message` in the
  `leastudios_snippets_error_{id}` transient (`DAY_IN_SECONDS`), fires the existing
  `leastudios_snippets_php_error` action, and invalidates the active-IDs object cache.
- `record_warnings(int $snippet_id, array<int,string> $messages): void` — stores warnings
  in a `leastudios_snippets_warnings_{id}` transient (`DAY_IN_SECONDS`). Does **not** change
  `META_ACTIVE` and does **not** add the snippet to the safe-mode list.
- `on_shutdown(): void` — the registered shutdown handler (see §2).

State keys (exposed as public class constants for `Safe_Mode_Notice` and `uninstall.php`):

- Option: `leastudios_snippets_safe_mode` (existing).
- Error transient prefix: `leastudios_snippets_error_` (existing).
- Warnings transient prefix: `leastudios_snippets_warnings_` (new).

**Cache-invalidation fix.** `deactivate()` changes `META_ACTIVE` via `update_post_meta()`,
which does not fire `save_post`, so the existing active-IDs cache hooks would not see it.
`deactivate()` therefore calls `Snippet_Executor::invalidate_active_cache()` explicitly.
(This also fixes a latent bug in today's `handle_php_error()`, which never invalidated.)

### 2. Fatal-error containment

`Snippet_Executor::maybe_execute_snippet()` wraps the dispatch to `execute_php()` /
`execute_output()` in `Safe_Mode::begin()` / `end()`:

```
$previous = $safe_mode->begin( $snippet->ID );
try {
    // existing dispatch
} finally {
    $safe_mode->end( $previous );
}
```

`Safe_Mode::on_shutdown()` decision, expressed as a pure, testable method
`decide_culprit(?array $last_error, ?int $executing_id): ?int`:

> Return `$executing_id` **only if** the marker is still set (`$executing_id !== null`)
> **and** `$last_error` is non-null with a `type` in the fatal-class set:
> `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`,
> `E_RECOVERABLE_ERROR`. Otherwise return `null`.

The fatal-class requirement prevents a critical false positive: a snippet that legitimately
ends the request with `wp_safe_redirect( … ); exit;` leaves the marker set but produces **no**
fatal error, so it is **not** deactivated. Only a genuine PHP fatal — out-of-memory,
execution timeout, or an uncaught fatal in a deferred hook callback — trips deactivation.

`on_shutdown()` calls `decide_culprit( error_get_last(), $this->executing_snippet_id )`;
on a non-null result it calls `deactivate()` with a message derived from `error_get_last()`.

The existing per-`eval()` `try/catch (\Throwable)` is retained — it still gives immediate,
in-request handling of catchable `Error`/`Exception` thrown by immediate-execution code.
The shutdown handler complements it by covering what `try/catch` cannot.

### 3. Errno filter + warning recording

The custom error handler installed inside `execute_php()` for the duration of `eval()`
classifies each error by `$errno` into two buckets:

- **Error-class** — `E_USER_ERROR`, `E_RECOVERABLE_ERROR` (the only error-severity levels a
  userland `set_error_handler` callback actually receives). Captured as a fatal message.
- **Warning-class** — everything else the handler receives (`E_WARNING`, `E_NOTICE`,
  `E_DEPRECATED`, `E_USER_WARNING`, `E_USER_NOTICE`, `E_USER_DEPRECATED`, `E_STRICT`).
  Collected into a warnings array.

After `eval()` returns:

- A caught `\Throwable` **or** an error-class message present → `Safe_Mode::deactivate()`.
- Otherwise, if the warnings array is non-empty → `Safe_Mode::record_warnings()`; the snippet
  stays active.

**Scope note (explicit, not a silent gap):** this warning capture covers code running
*during* `eval()` — i.e. immediate-execution snippets. Warnings emitted later by deferred
hook callbacks are not captured, the same as for any other plugin's code. Deferred *fatals*
are still covered by the §2 shutdown handler.

### 4. Surfacing warnings and oversized code

**Warnings.** `Safe_Mode_Notice::render_notices()` gains a second pass: for every snippet
that has a `leastudios_snippets_warnings_{id}` transient, render a dismissible `notice-info`
listing the warning messages plus an "Edit Snippet" link. Distinct styling (`notice-info`)
from the deactivation notice (`notice-warning`) signals "still running, but check this."
The warnings transient is deleted when the snippet is re-saved (hooked on
`save_post_leastudios_snippet`) — re-saving signals the author has addressed it; if the
warning recurs on the next execution it is simply re-recorded.

**Oversized code.** `Snippet_Editor::save()`: when the submitted code exceeds
`MAX_CODE_BYTES`, instead of silently skipping the `update_post_meta()` call, set a one-shot
`leastudios_snippets_oversize_{post_id}` transient with a 60-second TTL. `Snippet_Editor`
registers an `admin_notices` callback that, on that post's edit screen, renders a
`notice-error` ("Snippet code exceeded the 256 KB limit and was not saved") and deletes the
transient. The previously stored code value is left intact (unchanged behavior — now visible
to the user instead of silent).

### 5. `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT` gating

New `Snippet_Post_Type::is_editing_disabled(): bool` — returns `true` when `DISALLOW_FILE_MODS`
or `DISALLOW_FILE_EDIT` is defined and truthy. The computed value is passed through a new
`leastudios_snippets_editing_disabled` filter; this is both a genuine extension point and the
test seam (lets tests flip the state without `define()`-ing a constant).

Consumers:

- **`Snippet_Post_Type::map_capabilities()`** — when editing is disabled, the snippet
  **write** capabilities (`edit_*`, `delete_*`, `publish_*`, `create_*`) map to
  `[ 'do_not_allow' ]`; the **read** capabilities (`read_*`, `read_private_*`) still map to
  `[ 'manage_options' ]`. WordPress then hides "Add New" and edit/row actions and blocks
  saves through every path (post editor, REST, WP-CLI) with one change.
- **`Library_Page`** — `handle_install()` calls `wp_die()` with an explanatory message when
  editing is disabled; `render_page()` shows a banner and omits the per-card Install forms.
- **Explanatory notice** — a `notice-info` on snippet admin screens stating that editing is
  disabled because `DISALLOW_FILE_MODS` (or `DISALLOW_FILE_EDIT`) is set, so an admin is not
  confused by the missing "Add New" button.

**Deliberately *not* gated:** already-saved snippets continue to **execute** — these
constants govern *modifying* code, not disabling already-installed code (mirroring how
already-installed plugins keep running). Reactivating a snippet from safe mode also stays
allowed: it is a state change, not a code modification.

### 6. Testing

The WordPress test library will be installed first
(`leastudios-dev-tools/bin/install-wp-tests.sh`), then the suite is developed test-first.

New test files (extending the existing `tests/TestCase.php` → `WP_UnitTestCase` pattern):

- **`SafeModeTest`** — `decide_culprit()` truth table, including the redirect false-positive
  case (marker set + no fatal → `null`); `deactivate()` writes (`META_ACTIVE`, option,
  transient, action fired); `record_warnings()` does *not* deactivate; `begin()`/`end()`
  marker nesting (`begin(1)`, `begin(2)`, `end → 1`, `end → null`).
- **`SnippetExecutorTest`** — errno split: a snippet emitting a notice during `eval()` stays
  active with a warnings transient; `trigger_error( …, E_USER_ERROR )` or a thrown exception
  deactivates it. Output wrapping for `js`/`css`/`html` and the `leastudios_snippets_output`
  filter. Shortcode handler basics.
- **`SnippetLibraryTest`** — `install_snippet()` with a valid slug creates an inactive post
  with the expected meta; an unknown slug throws `InvalidArgumentException`;
  `get_available_snippets()` filters by `requires_plugin`.
- **`SnippetPostTypeTest`** — `map_capabilities()` maps write caps to `manage_options`
  normally and to `do_not_allow` when editing is disabled (toggled via the
  `leastudios_snippets_editing_disabled` filter); read caps unaffected.

### 7. Documentation

- `CLAUDE.md` — rewrite the safe-mode section: containment now covers deferred and
  uncatchable fatals; document the new `Safe_Mode` class and the editing-disabled gate.
- `README.md` — note warning recording and the `DISALLOW_FILE_MODS` behavior.
- `docs/developer-handbook.md` — document the new `leastudios_snippets_editing_disabled`
  filter and the changed deactivation/warning behavior.
- `uninstall.php` — generalize the transient cleanup to drop all
  `_transient_leastudios_snippets_%` / `_transient_timeout_leastudios_snippets_%` rows,
  covering the new warnings and oversize transients.

## Files touched

**New:**

- `src/Execution/Safe_Mode.php`
- `tests/SafeModeTest.php`, `tests/SnippetExecutorTest.php`, `tests/SnippetLibraryTest.php`,
  `tests/SnippetPostTypeTest.php`

**Modified:**

- `src/Plugin.php` — construct `Safe_Mode`, call `init()`, inject into `Snippet_Executor`.
- `src/Execution/Snippet_Executor.php` — remove safe-mode logic (delegate to `Safe_Mode`);
  errno bucketing in `execute_php()`; `begin()`/`end()` marker around dispatch.
- `src/CPT/Snippet_Post_Type.php` — `is_editing_disabled()`; gate write caps in
  `map_capabilities()`.
- `src/Admin/Snippet_Editor.php` — oversized-code notice; clear warnings transient on save.
- `src/Admin/Safe_Mode_Notice.php` — render warning notices; render the editing-disabled
  explanatory notice.
- `src/Admin/Library_Page.php` — block install + banner when editing is disabled.
- `uninstall.php` — generalized transient cleanup.
- `CLAUDE.md`, `README.md`, `docs/developer-handbook.md`.

## Out of scope

- Capturing warnings emitted by deferred hook callbacks (only immediate-`eval()` warnings
  are recorded — see §3).
- Any change to how snippets are stored, the CPT schema, or the conditions system.
- Interfering with WordPress core's own `WP_Fatal_Error_Handler` / recovery mode.
- Per-user dismissal persistence for the warning notice (it clears on snippet re-save or
  transient expiry).

## Success criteria

1. A snippet whose deferred `wp_head` callback triggers a fatal is auto-deactivated and
   appears in safe mode after one broken request — it does not break subsequent requests.
2. A snippet that performs a legitimate `wp_safe_redirect(); exit;` is **not** deactivated.
3. A snippet emitting only an `E_NOTICE`/`E_DEPRECATED` stays active and surfaces a
   non-blocking admin notice.
4. Oversized snippet code produces a visible admin error notice instead of silent loss.
5. With `DISALLOW_FILE_MODS` set: creating, editing, and library-installing snippets are
   blocked through UI/REST/CLI; existing snippets still execute; an explanatory notice shows.
6. `composer lint` is clean (PHPCS + PHPStan level 6, no baseline) and `composer test`
   passes, including the four new test files.
