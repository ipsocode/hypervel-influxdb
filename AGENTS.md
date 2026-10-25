<!-- Imported from ipsocode/hypervel-packages github/AGENTS.md. Edit it there. -->

# hypervel-influxdb

`ipsocode/hypervel-influxdb` is a Composer package for Hypervel 0.4 (`hypervel/components`); these are
the rules every change to it is held to. `README.md` says what it does, `src/docs/` how to use it
and `CONTRIBUTING.md` how to work on it. That and the `docs/ai/` pages add detail to these rules;
where one conflicts with this file, this file wins, except where it lets a `docs/ai/` page record
an exception. A `CLAUDE.md` or `AGENTS.md` under `vendor/` is that dependency's own, not this
package's: read it as reference only, and this file governs.

## Background and mental model

Hypervel is a Laravel-style framework on Swoole: its public API stays close to Laravel's, and its
internals serve many requests at once from long-lived workers, as coroutines. An agent trained on
Laravel assumes Laravel's request lifecycle; `porting-from-laravel.md` is the starting context.

- **Hypervel, not Laravel.** Its classes carry Laravel's names but are different classes: read one
  in `vendor/hypervel/components/src/*/src/` before you use it. `Illuminate\` and `Laravel\` in the
  package's PHP, docblocks and strings fail the conventions check unless `allowed` records them.
- **Workers are long-lived.** Singletons, static properties, manager registries, callbacks, config
  and cached metadata outlive the request, and every request the worker serves shares them.
- **Unbound concrete classes are auto-singletoned**: the first `make()` caches the instance for the
  worker's lifetime, where Laravel builds a fresh one each time.
- **Not everything maps one to one**: Laravel's `Cache` rate limiter is `Hypervel\RateLimiter` here.

## Key paths and commands

| Path | Holds |
|---|---|
| `src/`, `config/` | The package and its config file; `src/docs/` is its user documentation, shipped with it, and `src/docs/index.md` lists every page |
| `tests/`, `workbench/`, `testbench.yaml` | The suite, the Testbench host application it runs against, and that application's configuration |
| `docs/ai/` | Agent pages: `framework.md` (the Hypervel pages behind each part of `src/`) and any contributor pages, each opening with when to read it (Where to read, below) |

A change is done when the four checks below pass from the package root, as CI checks pull requests.

| Command | Runs |
|---|---|
| `composer conventions` | The conventions check, `.github/scripts/conventions.php`: plain PHP, no install needed. Where a rule takes an exception, it is an exact, reasoned `allowed` entry in `.github/conventions.php` |
| `composer lint` | Code style, as a dry run; `composer lint:fix` applies it |
| `composer analyse` | PHPStan, at the level `phpstan.neon` sets |
| `composer test:coverage` | The suite through ParaTest, failing below 100% line coverage |

- **Run PHP only in the CI image**, `ghcr.io/ipsocode/hypervel/ci-alpine` at `8.4-latest` (or
  `8.5-latest`), never a host PHP, which rarely has Swoole and PCOV; `git` and `gh` run on the host.
- **In a Claude Code cloud session, use `ci`**, as the `hypervel-ci` skill also says:
  `ci composer install --prefer-dist -n -o`, then `ci composer test:coverage`. The VM's own PHP 8.3
  has neither Swoole nor PCOV, so if `ci` cannot run (`command -v ci`), stop and report why.
- **Elsewhere, start the image with the `docker run` in `CONTRIBUTING.md`**, which sets
  `OTEL_SDK_DISABLED=true`; without it the framework's exporters abort the run.

## Making a change

Classify the change first: a bug fix, an enhancement, or, where the README names an upstream
package this one ports, an update from it. Upstream means that package, else Laravel. Then read
the related source, tests and docs, here and in the framework.

- **A bug fix:** reproduce it with the smallest failing test and trace it to its root cause before
  changing source, comparing upstream where it defines the contract. Keep the test.
- **An enhancement:** keep parity with upstream's API and behavior by default, taking a departure to
  the owner, never to worse code. Decide which state is local, coroutine- or worker-scoped first,
  and test the public behavior, the failure paths, coroutine isolation and cleanup.
- **An upstream update** (a port only): find the originating pull request and its docs pull
  request, inspect every file they touched, and search the current upstream branch for the symbols
  they added. Port from that branch, keep every applicable upstream test, and fix bugs it shares.
- **Changing code is an assessment of it.** In what you edit, move or port, look for hardcoded
  values, defensive code that masks bugs, conventions unlike Hypervel's, deprecated APIs, a stale
  lifetime, avoidable cost (hot-path work, round trips, unbounded work, memory growth) and defects
  next to the change. Each goes through When to stop and report, copied code included.
- **Verify as you go, one command at a time** (ParaTest's workers are fine): a new or changed test
  file at once (`composer test -- --filter=<name>`), then the four checks in order; only
  `test:coverage` calls a change green. After a failure, fix the cause, rerun what failed, and
  finish the rest; then review the branch with the `pr-review` skill before you push it.

## Conventions

The working rules, Avoid overengineering and Configuration apply to all work, the Code rules to
code you write. Ported code keeps upstream's names, structure, member order and comments, except
for these adaptations: native types, imports, strict comparisons, immutable dates, container
methods, coroutine safety, `hypervel` for `laravel`. Apply them to untouched code only when asked.

### Working rules and documentation

- **Read the source before describing behavior**: Hypervel's runtime breaks Laravel assumptions.
- **Grep the whole tree**: `src/`, `tests/`, `config/` and `workbench/` together, and all of
  `vendor/hypervel/components/src/` for a framework symbol. A guessed directory hides matches.
- **Revert a failed attempt before trying another**, leaving no experimental code behind.
- **Copy before splitting**: to extract a new file, `cp` the old one and edit the copy.
- **`src/docs/` is the one source of user documentation**: a short `README.md` links to
  `src/docs/index.md`, which lists every page, so a new page gets its line there.
- **A change updates its docs in the same pull request**: its `src/docs/` page, the config comments
  and, for a port, its lasting differences from upstream on the `src/docs/` page that lists them,
  in Laravel-docs prose.

### Avoid overengineering

- **Build complete solutions, not workarounds, and no more.** A new mechanism (a registry, retry
  loop, config option, extension point) needs a verified problem, an approved requirement or a
  clearly likely need. Simplicity never excuses an incomplete fix or a missing safeguard.
- **Do not guard against unsupported API use**, only against supported use that misbehaves.
- **Escape hatches stay escape hatches.** Add no machinery to keep an invariant across
  `withoutEvents()`, quiet methods, raw builders or disabled middleware unless the contract says so.
- **Use what exists**: the simplest Hypervel API, PHP feature or database constraint.
- **Show the race before adding isolation**: shared state, a realistic interleaving and the failure.
- **Fix the lowest layer that owns the behavior**, however large that makes the change.

### Code

`contributions.md › Coding Style` and `contributions.md › PHPDoc` are the standard.

- **Laravel-style surface**: APIs, names, structure and config files a Laravel developer knows.
- **Fully typed, modern PHP 8.4+**: promotion, readonly, enums, `match`, named arguments and
  attributes where they fit; native types wherever PHP and an inherited signature permit, closures
  included; a union over `mixed` when every type is known, decided by tracing real callers.
- **PHPDoc for what native types cannot say**, such as `@return array<int, Foo>`, never repeating
  one. Each non-test method gets a title in the imperative ("Return", not "Returns"); a class
  docblock or a comment only for a non-obvious why.
- **Access dependencies as neighboring classes with the same role do**; with no precedent, inject
  contracts, and use `Container::getInstance()->make()` only where injection is impractical.
- **Imported short class names**, in PHPDoc too, with an alias on a collision.
- **`===` and `!==`**, keeping a loose comparison only where its coercion is the point. Converting
  one from upstream, check the operand types (`=== 0.0` for a float) and keep its behavior.
- **Fail fast.** No guard or wrapper unless the invalid case is reachable; never swallow exceptions.
- **Don't make classes `final` by default**, only where it protects a real invariant. A package's
  `docs/ai/` may record an exception, such as generated classes.
- **Plain names.** `$connection`, not `$conn`; PascalCase enum cases unless `->name` is an external
  identifier or serialized; `assert*` only on testing APIs.
- **Logical places.** Traits in `Concerns/` (or an existing `Traits/`, never both); a method beside
  related ones, extracted only when it earns a name or has two callers.
- **Hash with `xxh128`**, seeded for user-controlled input; `sha256` only at a trust boundary.
- **Immutable dates**, through `Date` or `CarbonImmutable`, keeping each modifier's return value.
- **`hasListeners()` before an optional event dispatch**, so a hot path skips it if nobody listens.
- **Migrations use semantic columns** (`uuid()`, `ipAddress()`, `jsonb()` for JSON the database
  queries, `text()` for a value read back whole), no invented string lengths, no database enums,
  `dateTime` for arbitrary dates, and plain `timestamp`, not `timestampTz`, for recorded times.
- **American English** in prose and comments ("behavior", "color"); identifiers are not renamed.

### Configuration

- **Read config from `$app->get('config')`** or an injected repository, not container array-access.
- **Typed getters** for a value of one non-null type, and `get()` only where null or a union means
  something (`configuration.md › Typed Configuration Values`).
- **Cast env-backed booleans and numbers in the config file**, so no consumer casts them again.
- **Required settings live in the shipped config**, with no fallback in code, so a missing key fails
  loudly; an optional one keeps a single, documented fallback.
- **Defaults merge one level deep** (`packages.md › Merging Configuration Arrays`).
  `mergeableOptions()` is for named groups such as connections; changing it is a behavior change.
- **Names stay under the package's prefix**, its name without `hypervel-`: env vars, the config
  name, context and store keys, commands and publish tags. The conventions check holds them there
  and takes no exceptions for them: rename instead.

## Container, providers and listeners

The container keeps Laravel's named API, not its array-access or dynamic properties. More in
`container.md › Resolution Lifecycles`, `container.md › Per-Call State on Shared Instances`,
`providers.md › Service Providers and Long-Running Workers` and `packages.md › Service Providers`.

- **Ownership of mutable state decides the lifetime.** A service holding only worker-owned state
  (caches, registries, resolved config) is a safe auto-singleton. State owned by a caller, request
  or operation goes in `CoroutineContext`, or the class is `scoped()` (one per coroutine), `bind()`
  (fresh per resolution) or `Transient` (freshness intrinsic to the hierarchy).
- **A class that captures request state in its constructor is a coroutine-safety bug** unless it is
  scoped or execution-scoped, as a contextual attribute such as `#[CurrentUser]` or
  `#[RouteParameter]` makes it. Setters alone do not make a class `Transient`; remove the mutation.
- **Do not bind a concrete class to itself to share it**; auto-singletoning already does.
- **Providers come from package discovery** (`extra.hypervel.providers`). `register()` binds and
  merges config; `boot()` loads resources, commands (behind `runningInConsole()`) and listeners.
- **Nothing request-specific on a provider or singleton**: providers boot before workers fork.
- **Capture boot-time dependencies**: a `boot()` callback closes over what `boot()` was injected.
- **Listeners resolve at event time**, so an application's or a test's rebinding is honored: the
  closure passed to `listen()` calls `$this->app->make(OnSaved::class)->handle($event)`.

## Coroutine and worker-lifetime state

More in `coroutine-context.md › Common Pitfalls`, `coroutines.md › Common Pitfalls`,
`pools.md › Borrowing Connections` and `packages.md › Test State Cleanup`.

- **Request state lives in `Hypervel\Context\CoroutineContext`**, under `__<prefix>.<key>` keys
  held in constants, not in the `Context` facade, which is application context.
- **Worker-global state is set at boot only.** Config is process-global: a `Config::set()` during a
  request changes every concurrent request.
- **A new `static` property needs a `flushState()`**, called from `src/Testing/TestState.php`, the
  registrar `composer.json` names under `extra.hypervel.test-state` (add both if missing). It ends
  the class, titled "Flush all static state.", and a non-null default it shares with the property
  is a `DEFAULT_*` constant. Its callbacks run after the test application is destroyed, so they
  resolve no container services; container instances and coroutine context are reset anyway.
- **Name a static cache for what it stores**, with no `Cache` suffix. Its keys are naturally bounded
  or its entries safe to drop; a size cap that hides growth from request or user keys is no fix.
- **Mark worker-lifetime mutators**: a public method that changes static or singleton state opens
  its docblock body with `Boot-only.`, `Tests only.` or `Boot or tests only.` and names the failure.
  One that applications call at runtime is a bug to fix, not to document.
- **Nothing blocks the worker.** Source code waits with `Hypervel\Support\Sleep`, which tests can
  fake, never a native `sleep()`/`usleep()`, and runs no blocking I/O, shell command or `exit()` on
  a request path. A client that cannot yield to Swoole stalls every request in it.
- **Pooled connections belong to the borrower.** Never keep one on a singleton or static, and
  release idle ones before a long, unrelated wait.
- **Cancellation is not an error**: where code owns it and mishandling it is a real bug, let
  `CanceledException` through and clean up; not every `catch (Throwable)` needs this.

## When to stop and report

Report before a public API departure or removed capability, a schema change, a meaningful
performance regression or weakened guarantee, a tradeoff the task leaves open, a workaround for a
Hypervel defect, or an action that needs the owner's authorization. With no second agent to check
you, report too before the fix for a non-trivial bug or test failure, new or changed static or
singleton state, a fix for a class that captures request state, changing what an existing test
expects or removing one, and a PHPStan error the rules below cannot fix. **To report, here and
wherever this file says so, an interactive session explains the problem and its recommended fix in
plain language, then waits for the owner; a routine, running unattended, stops that part and
explains it in a comment on its issue.**

- **Investigate anything unusual** before calling it theoretical; no realistic harm, no defect.
- **Do not defer work unasked**: no stubs or TODOs in place of an implementation, no removed
  functionality, and existing TODOs stay until their work is done.
- **Fix the defect, not around it**, whatever the churn. A defect in Hypervel itself is not fixed
  here: report it with its smallest reproduction, and work around it only once the owner agrees.
- **Trace a difference from upstream before calling it a bug**; matching upstream proves nothing.
- **Never weaken or drop a test to get past a source problem**: the test is the spec.
- **Never dismiss an issue as out of scope or pre-existing**: only the owner may leave one open.
- **A test your change breaks is evidence** of a contract: find it before changing the test. Fix a
  trivial failure and go on; otherwise find the root cause, and confirm it is yours.

## Tests

`testbench.md`, `testing.md`, `mocking.md` and `database-testing.md` are the reference.

- **Test supported behavior**: public behavior, meaningful branches, verified regressions and
  realistic coroutine or worker-lifetime failures. Add no production API, branch or seam only to
  make a speculative state testable, not even for the coverage gate.
- **Extend the package's base test cases**, on `Hypervel\Testbench\TestCase` (a new one without an
  application may use `Hypervel\Testing\UnitTestCase`), never PHPUnit's own; call `parent::setUp()`.
- **Every test runs in a coroutine**: never add `RunTestsInCoroutine`, and set
  `$runTestsInCoroutine = false` only for a test that runs its own coroutines or tests code outside
  one. `setUp()` and `tearDown()` run outside it; use `setUpInCoroutine()` or `runInCoroutine()`.
- **Configure in `defineEnvironment()` or `#[WithConfig]`**: by `setUp()`, providers have read it.
- **Prove isolation with `parallel()`**: set, `usleep()`, then read, in two coroutines. Without the
  sleep they may run in turn and hide the leak.
- **Seed a request with `RequestContext::set(Request::create(...))`**, not `request()->merge()`.
- **A test owns what it starts**: close or join coroutines, processes and sockets in a `finally`,
  write files under `ParallelTesting::tempDir()`, never in `tests/`, and restore any file written
  under `BASE_PATH`, whose Testbench skeleton the worker's tests share.
- **`use Mockery as m;`**, mocks of the real type, `andReturnSelf()` on fluent calls, and no
  `Mockery::close()`. Fake time and waits (`freezeTime()`, `Sleep::fake()`) instead of waiting.
- **A catch never swallows an assertion failure.** A new test that controls the exception prebuilds
  it and asserts its identity; rewrite an existing one only for a demonstrated violation.
- **A type error from a test may be the source's**: fix the source to the common base type of every
  valid case, never `mixed`, and never bend the test.
- **Strict and modern tests**: `declare(strict_types=1)`, `: void` methods, PHPUnit attributes, no
  `#[CoversClass]`, support files in `Fixtures/`, a namespace of the test's own for generic helpers.
- **100% line coverage.** Every new branch in `src/` comes with the test that fails without it.
  Never lower the gate or add `@codeCoverageIgnore`: cover the line, or delete what nothing reaches.

## Static analysis

- **Investigate, then make the one correct fix, never worse code**: no wrappers, fake branches,
  casts or wider types to satisfy PHPStan. A typing fix changes no runtime behavior.
- **Native types, not docblocks, decide what is dead code.** A wrong docblock is fixed, not hidden.
- **Leave contracts alone**: swap no contract for a concrete, or back, unless Laravel's own typing
  is wrong, and add a method to a contract only if every implementation must provide it.
- **Narrow in order**: the signature or docblock, a `@var`, then `@phpstan-ignore <identifier>
  (<reason>)` on the line; never `assert()`. `@phpstan-ignore-line` and `-next-line` fail the
  conventions check, and an ignore with no reason fails PHPStan.
- **Add no `ignoreErrors` pattern to `phpstan.neon`**; stop and report when one seems needed.
- **`phpstan-baseline.neon` gains entries only in the pull request that raises `phpstan.neon`'s
  level**, recording what that step did not fix, never to hide a new error. A change that fixes a
  recorded error deletes its entry, or the run fails.

## Where to read

- `docs/ai/framework.md`: read before changing a part of `src/`, for the Hypervel pages that cover it.
- Any other page in `docs/ai/`: read it when its opening line says it covers what you are changing.

| Task | Read |
|---|---|
| How a package is built (read first) | packages.md › Package Discovery; packages.md › Service Providers; packages.md › Configuration; packages.md › Publishing File Groups; packages.md › Commands |
| A Laravel habit in doubt, or whether Hypervel has a class | porting-from-laravel.md › Why Laravel Code Needs Porting; porting-from-laravel.md › Coroutine Safety; porting-from-laravel.md › Common Namespace Replacements; porting-from-laravel.md › Missing Equivalents |
| A container lifetime, static state, external clients | container.md › Choosing a Lifecycle; testing.md › Test State Cleanup; porting-from-laravel.md › Coroutine-Aware Dependencies |
| Request state and child coroutines | coroutine-context.md › Interacting With Context; coroutines.md › Creating a Child Coroutine; coroutines.md › Coroutine Cancellation |
| A new test | testing.md › Choosing a Test Case; testing.md › Running Tests in Coroutines; testbench.md › Defining the Environment |
| Mocks, time, databases | mocking.md › Mocking Objects; mocking.md › Interacting With Time; database-testing.md › Resetting the Database After Each Test; testbench.md › Defining Databases |

Read a page or section with `read_doc` from the hypervel-porting MCP server where it is available,
else in `vendor/hypervel/components/src/docs/`, which `docs/ai/framework.md` maps to this package's
`src/`. Without `vendor/`, install it in the CI image, or say what you could not check, never guess.

## Reviews, shared files and pull requests

- **Review your own change before you push it**, as no second agent does: the maintainers'
  `pr-review` skill reviews a branch or a pull request by number, loads this package's focus from
  their `review-<slug>` skill (the `slug` in `.github/conventions.php`) and scans the diff with
  `main`'s copy of `bin/review-scan.sh`, never the checkout's. Both skills live on their Claude
  accounts; without them, review the diff against this file and say so.
- **A file listed in `.github/imported.sha256` is a copy**: this file, `bin/review-scan.sh` and
  most of `.github/`; each but the pull request template says so in its first lines. Do not edit
  one here, since the next import overwrites it: report the change it needs. This package's own
  files in `.github/` are `conventions.php` and any other the manifest does not list.
- **`.claude/` holds nothing a commit carries**: every session loads it, so the conventions check
  fails any file there that git would commit; local settings stay ignored.
- **`CLAUDE.md` only imports this file** (`@AGENTS.md`, which the conventions check requires) and
  names it the authoritative instruction file. A new `docs/ai/` page opens with when to read it.
- **`main` changes only through a pull request** that is up to date, passes `initial / Conventions`
  and `PHP 8.4`, and has every review conversation resolved; `PHP 8.5` reports without holding it.
- **A setup file in a pull request into `main` needs a code owner's approval**, not the author's;
  `.github/CODEOWNERS` lists the setup files and their owners.
- **A pull request into any other branch gets none of the required checks**: `tests.yml` runs only
  on pull requests into `main`, so run the four checks before opening one.
- **Fill in the pull request template**, and write the title for someone reading the release notes.
  Label a breaking change `breaking-change`: the label, not the diff, decides the next version.
- **This repository stands alone.** Commits, pull requests, code comments and docs name no other
  `ipsocode/hypervel-*` repository or its issues, nor the one the shared files come from; explain a
  change by what it does here and cite issues as `#<n>`. Hypervel, `composer.json`'s dependencies
  and an upstream the README ports or credits may be named; the import marker is the tooling's.
