# Contributing

This package is development-only until Hypervel 0.4 is released — see the
notice at the top of the [README](README.md). Changes land on `main` through
pull requests.

## Development setup

The suite needs PHP 8.4 or 8.5 with the Swoole and Redis extensions that
`hypervel/components` requires, plus PCOV for the coverage gate. It needs no
InfluxDB server: `InfluxDB2\Client` opens no connection when it is
constructed, so the suite resolves and inspects clients without ever writing a
point. CI runs in `ghcr.io/ipsocode/hypervel/ci:<php>-latest`, which has all of
the extensions, and is the simplest way to match it:

```sh
docker run --rm -it -v "$PWD":/app -w /app -e OTEL_SDK_DISABLED=true \
    ghcr.io/ipsocode/hypervel/ci:8.4-latest bash
composer update
```

`OTEL_SDK_DISABLED=true` matters in that image: it has no protobuf extension,
and without the variable the framework's OpenTelemetry exporters abort the run
before the first test.

No `composer.lock` is committed. Every install resolves against the current
`hypervel/components` `0.4.x-dev` on Packagist, as CI does, so an upstream
change that breaks this package shows up here first. Composer downloads
GitHub-hosted packages through GitHub's API; give it a GitHub token (for
example through `COMPOSER_AUTH`) if you hit the anonymous rate limit.

For Claude Code, `docs/.mcp.json` configures InfluxData's documentation
server, `influxdb-docs`, so a session can look up how each InfluxDB version
behaves. Claude Code reads servers only from a `.mcp.json` at the repository
root: copy it there (`.gitignore` keeps that copy out of commits), then run
`/mcp` once to sign in.

## Checks

| Command | What it runs |
|---|---|
| `composer conventions` | The conventions check (below), in plain PHP: no install needed |
| `composer lint` | php-cs-fixer in dry-run mode; `composer lint:fix` applies the fixes |
| `composer analyse` | PHPStan at level 5 over `src/` |
| `composer test` | The suite through the Testbench CLI |
| `composer test:phpunit` | The suite through PHPUnit directly, bypassing the Testbench CLI |
| `composer test:parallel` | The suite through ParaTest |
| `composer test:coverage` | The suite under PCOV, failing below 100% line coverage |
| `composer test:purge` | Clears the Testbench skeleton's cached config, routes, views and SQLite files |

Arguments after `--` reach PHPUnit or ParaTest, e.g.
`composer test -- --filter=InfluxDBManagerTest`.

`workbench/` is the host application the suite runs against: it owns the
connection config the tests resolve (`workbench/config/influxdb.php`) and a
few console commands that drive the manager the way an application would
(`workbench/routes/console.php`). `testbench.yaml` declares what it discovers.
The suite never reaches a live InfluxDB: a URL in it is only config, and the
HTTP layer underneath is a Guzzle mock (`tests/Concerns`).

php-cs-fixer and PHPStan are configured like `hypervel/components`:
`.php-cs-fixer.dist.php` loads its rules, verbatim, from
`.github/php-cs-fixer-rules.php`, and `phpstan.neon` carries its level,
analysis settings and framework extensions. Keep them in step when upstream
changes, so a finding here is a finding there. Some of the fixer's rules are
risky — they change behaviour, not only formatting — so run `composer test`
after `composer lint:fix`, not just before committing. An inline PHPStan ignore
names its error and says why: `@phpstan-ignore <identifier> (<reason>)`.

The conventions check, `.github/scripts/conventions.php`, fails CI on what a
reviewer used to check by eye: `Illuminate\` or `Laravel\` anywhere (docblocks
and strings too), container array-access, `@codeCoverageIgnore`, a bare
`@phpstan-ignore-line`, a coverage gate below 100%, and raw SQL, `eval()`,
`unserialize()`, shell commands, `sleep()` or `exit` in shipped code. It also
holds the names this package writes where the application writes too: context
keys `__influxdb.*`, cache and lock keys `influxdb:*`, commands
`influxdb:<verb>`, publish tags `influxdb-*`, env vars `INFLUXDB_*` and
`config/influxdb.php`. Each violation is reported on its line. This package's
settings are `.github/conventions.php`, and its exceptions are exact, reasoned
entries in the `allowed` list, `'<path>' => [<exact number of hits>, '<why>']`:
the raw-query rule meets the raw API this package's InfluxQL builders and driver
implement, safe because values go through bindings and anything written as-is,
such as a `groupByTime()` duration, is checked first. The check fails once a
count stops matching either way, so a new raw call has to be argued for.

The automated review also runs `.github/scripts/review-scan.sh`, which flags what
a diff adds or removes. To see what it will flag on your branch:
`git diff origin/main...HEAD | bash .github/scripts/review-scan.sh`. The
review's instructions are the `pr-review` skill,
`.github/claude/skills/pr-review/SKILL.md`: ask Claude Code to follow it on your
branch to get the same review before you push.

CI (`.github/workflows/tests.yml`) runs for every pull request, each job once
the one it needs has passed: the conventions check, on the bare runner in
seconds (`.github/workflows/initial.yml`); code style, PHPStan and the suite
under the 100% coverage gate on PHP 8.4; then, side by side, PHPStan and the
suite on PHP 8.5, and the automated review. A push to a branch without a pull
request runs nothing, so open a draft pull request to get CI early; the
automated review runs once the pull request is marked ready, on each commit that
passes the conventions check and PHP 8.4. It keeps notes between pushes, so a
push is reviewed for what it changed, and for whatever else in the pull request
that reaches. `main` is protected: it changes only through a pull request that
is up to date with it, passes those checks, and has every review conversation
resolved; a change that leaves a line of `src/` uncovered fails its own pull
request. The review never blocks on what it finds; it comments, and each comment
is a conversation to resolve.

Most of `.github/` is shared by the ipsocode/hypervel-* packages and imported
from one copy: the workflows, the scripts, the php-cs-fixer rules, the issue and
pull request templates, `CODEOWNERS`, `dependabot.yml`, the release-notes
config, the security policy, and the automated review's skill and brief in
`.github/claude/`, which the review installs on its runner for the review only.
Each of them but the pull request template says so in its first lines. A pull
request may still change one; the maintainer carries the change into the shared
copy, and the next import brings it to every package. Two parts of `.github/`
are this package's own: `conventions.php`, the check's settings, and `review/`,
which tells the automated review what this package is and where to look. The
package keeps no `.claude/`: `.gitignore` leaves Claude Code's local state out.

## Coroutine safety

A Hypervel worker is long-lived and serves many requests at once as coroutines,
so state that would be per-request in PHP-FPM is shared here. Every change is
held to these rules:

- No new `static` property unless it has a `flushState()` and is reset from
  `src/Testing/TestState.php`.
- Nothing on a request path creates a `WriteApi` or `QueryApi` per call. The
  upstream `Client` keeps a reference to every `WriteApi` it creates, so one
  per request is a leak for the life of the worker; `InfluxDBManager::writeApi()`
  memoises one per connection for that reason.
- No native `sleep()`/`usleep()`, blocking I/O or `exit()` on a request path.
- Hypervel APIs only, never `Illuminate\*`. Tests reach the container with
  `$app->get(...)`, never array access.
- New behaviour comes with a test. `@codeCoverageIgnore` is not a way past the
  coverage gate.

### Where the package keeps state

- **The manager's caches.** `InfluxDBManager` is a container singleton, so its
  resolved clients, memoised `WriteApi`s and InfluxQL connections live as long
  as the worker. They are instance properties, so they go when a test rebuilds
  the container. `reconnect()`, `disconnect()` and `setDefaultConnection()`
  change what every coroutine on the worker sees, and are for boot or tests
  only.
- **Batching writers' buffers.** A `Write\BatchingWriter`, memoised by the
  manager like any `WriteApi`, holds the points it has not sent in the worker,
  with its flush timer and the coroutines sending its full batches. Every
  change to the buffer is made without yielding, and a batch is taken out of
  it before its send yields, so coroutines that write meanwhile start new
  batches. The flush timer waits on the `WORKER_EXIT` coordinator, which
  Hypervel's test harness resumes after each test, so a test that leaves points
  buffered sends them as the test ends: give its transport a response for
  them. The provider flushes every writer when a console command finishes
  (`AfterExecute`) and when the console application terminates outside a
  coroutine (`Terminating`).
- **SQL connections.** Not the package's: the provider registers the
  `influxdb` database driver on Hypervel's `DatabaseManager`, which makes a
  `Sql\SqlConnection` for each slot of the connection's pool, and the pool
  hands each one to a single coroutine at a time. Every connection builds its
  own `Sql\SqlApi` from its InfluxDB connection's options, so no HTTP client
  is shared between them unless the options inject one.
- **InfluxQL database connections.** Not the package's either: the
  `influxql` driver works the same way, making an
  `InfluxQL\Driver\Connection` for each slot of the pool. Each wraps an
  `InfluxQL\Connection` of its own, rather than the manager's memoised one.
  That connection builds its own `QueryApi` from the options of the manager's
  client, so no HTTP transport is shared, with the manager or between these
  connections, unless the options inject an `httpClient`. Each keeps the
  server version the master process read, or asks the server once, before
  its first statement. Each also keeps its InfluxDB version and retention
  policy, so a grammar rebuilt while it is disconnected still refuses what
  that version cannot run. The driver's builder adds no static state; its
  macros are Hypervel's `Query\Builder`'s, which Hypervel flushes.
- **The servers' versions.** Read once in the master process when the server
  starts, before it forks its workers (`InfluxDBManager::detectServerVersions()`,
  on Hypervel's `BeforeServerFork`), and kept in the non-coroutine context under
  `__influxdb.server_versions`, which every worker inherits. Each ping closes its
  connection, so no socket crosses the fork. Hypervel's test harness flushes the
  non-coroutine context after each test.
- **`RefPoint`'s reflection cache.** A static, reset by `RefPoint::flushState()`.
- **The InfluxQL builder's macros.** A static, reset by
  `InfluxQL\Builder::flushState()`.

`Testing\TestState` flushes the static state. It is declared in
`composer.json`'s `extra.hypervel.test-state`, so a consuming application's
suite resets this package's state too. For the package's own suite that
registrar is discovered by the `AfterEachTestExtension` bootstrap in
`phpunit.xml` — without that entry the callbacks are declared and never run.

## Pull requests

Fill in the pull request template and label the pull request with the type it
ticks. There is no changelog file: each release's notes are generated from the
titles of the pull requests it contains, grouped by those labels
(`.github/release.yml`), so write the title for someone reading the release. A
pull request labelled `breaking-change` makes the next release at least a minor
version.

A change a consumer will observe, such as a config key, the InfluxQL a query
produces or what a driver reads back, goes in the template's *Behaviour change*
section, with the README update it needs in the same pull request.

## Releasing

For maintainers. A release is an annotated `vX.Y.Z` tag on `main` plus a GitHub
Release carrying the notes. Packagist reads versions off the tags, so nothing is
uploaded and `composer.json` carries no `version`: one that did not match the
tag would make Composer skip the tag.

Run the **publish** workflow on `main` (Actions → publish → *Run workflow*).
Leave the bump on `auto` — minor if a pull request merged since the previous
tag is labelled `breaking-change`, patch otherwise — or pick `patch`, `minor`
or `major`, or type an exact version. *Highlights* go at the top of the notes;
*dry run* shows the plan without tagging.

The workflow runs the full suite on PHP 8.4 and 8.5, with the coverage gate on
8.4, and only then tags the commit and publishes the Release. The notes are
generated from the pull requests merged since the previous tag. A `v*` tag
pushed by hand goes through the same suite and gets the same Release.

Composer installs a release as GitHub's archive of its tag, which leaves out
every path `.gitattributes` marks `export-ignore`: `.github/`, `docs/`, the
tests, the workbench and the development configs. A new top-level file or
directory ships unless it is added there; `git archive HEAD | tar -t` lists
what would.

### Packagist

Applications install the package from Packagist, as
`ipsocode/hypervel-influxdb`. Packagist crawls the repository whenever GitHub
tells it about a push, through a hook on the repository, so a tag can be
installed as soon as it is pushed, the publish workflow's tags included.
Without the hook, Packagist crawls it only once a week.

Setting that up is a one-time job for a maintainer:

1. Log in to [Packagist](https://packagist.org) via GitHub, and make sure the
   Packagist application has access to the `ipsocode` organization.
2. Submit `https://github.com/ipsocode/hypervel-influxdb`. Packagist crawls it
   straight away, and every existing `v*` tag becomes a version.
3. Check the package list for a warning that the package is not automatically
   synced. If there is one, trigger a manual account sync, or add the webhook
   by hand: payload URL
   `https://packagist.org/api/github?username=<your Packagist username>`,
   content type `application/json`, your Packagist API token as the secret,
   and just the push event.
