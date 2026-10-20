# Contributing

This package is development-only until Hypervel 0.4 is released — see the
notice at the top of the [README](README.md). Changes land on `main` through
pull requests.

## Development setup

User documentation lives in `docs/`, and notes for contributors on how the
package is built in [`docs/internals.md`](docs/internals.md).

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
`hypervel/components` `0.4.x-dev` on Packagist, as CI does, so a change in
`hypervel/components` that breaks this package shows up here first. Composer
downloads GitHub-hosted packages through GitHub's API; give it a GitHub token
(for example through `COMPOSER_AUTH`) if you hit the anonymous rate limit.

For Claude Code, `docs/.mcp.json` configures InfluxData's documentation
server, `influxdb-docs`, so a session can look up how each InfluxDB version
behaves. Claude Code reads servers only from a `.mcp.json` at the repository
root: copy it there (`.gitignore` keeps that copy out of commits), then run
`/mcp` once to sign in.

### Pins in `composer.json`

Three things in `composer.json` hold a version back on purpose. Each says when
it was set, why, and what lets it go; #19 lists everything that changes when
Hypervel 0.4 is released.

| Pin | Since | Why | It goes when |
|---|---|---|---|
| `"phpunit/phpunit": "~13.4.3"` | 2026-10-20 (`13.3.*` earlier that day, `>=13.0.3 <13.4` from 2026-10-02) | The constraint `hypervel/components` keeps in its own `require-dev`, with ParaTest `^7.27.2`, its floor for PHPUnit 13.4. Components' parallel runner builds PHPUnit's internal `PhpHandler`, whose constructor changed in 13.4.0, so an untested minor release can break `--parallel`, and with it `composer test:coverage`, before any test runs; Testbench conflicts with 13.5. | `hypervel/components` lifts its own constraint, once Testbench is verified on PHPUnit 13.5: move this one to match. Composer never reads a dependency's `require-dev`, and Dependabot is told to leave PHPUnit alone, so this is done by hand. |
| `"config": {"platform": {"ext-swoole": "6.3.0RC1"}}` | 2026-10-19 | `hypervel/components` `0.4.x-dev` requires `ext-swoole` `^6.3.0RC1`, and the CI image runs 6.3.0RC1. The pin makes every install resolve for that Swoole, whatever the machine running Composer has; a pin below what components requires fails resolution. | Swoole 6.3.0 is released and the CI image carries it: move the pin to it. Dependabot never reads `config.platform`, so this one is moved by hand. |
| `"hypervel/components": "0.4.x-dev"`, with `"minimum-stability": "dev"` and `"prefer-stable": true` | the first release | Hypervel 0.4 exists only as the `0.4.x-dev` branch. | Hypervel 0.4 is released: require `^0.4` and drop both stability keys (#19). |

## Checks

| Command | What it runs |
|---|---|
| `composer conventions` | The conventions check (below), in plain PHP: no install needed |
| `composer lint` | php-cs-fixer in dry-run mode; `composer lint:fix` applies the fixes |
| `composer analyse` | PHPStan over `src/`, at the level `phpstan.neon` sets |
| `composer test` | The suite through the Testbench CLI |
| `composer test:phpunit` | The suite through PHPUnit directly, bypassing the Testbench CLI |
| `composer test:parallel` | The suite through ParaTest |
| `composer test:coverage` | The suite under PCOV, failing below 100% line coverage |
| `composer test:integration` | The tests under `tests/Integration` against real InfluxDB servers (below) |
| `composer test:purge` | Clears the Testbench skeleton's cached config, routes, views and SQLite files |

Arguments after `--` reach PHPUnit or ParaTest, e.g.
`composer test -- --filter=InfluxDBManagerTest`.

`workbench/` is the host application the suite runs against: it owns the
connection config the tests resolve (`workbench/config/influxdb.php`) and a
few console commands that drive the manager the way an application would
(`workbench/routes/console.php`). `testbench.yaml` declares what it discovers.
The suite never reaches a live InfluxDB: a URL in it is only config, and the
HTTP layer underneath is a Guzzle mock (`tests/Concerns`). Only `tests/Integration` does.

### Integration tests

`tests/Integration` runs the package against real InfluxDB servers, outside the default suite:
`composer test` and `composer test:coverage` start no server and keep their 100% gate. Each test class
reads its server's URL from an environment variable and skips when it is unset, so the suite reports
skips, not failures, without a server. A class creates a database of its own and drops it afterwards,
so the suite can run again against the same server.

| Variable | Server | Tests |
|---|---|---|
| `INFLUXDB_V1_URL` | InfluxDB 1.x | `InfluxDBV1Test`, `InfluxDBV1VersionDetectionTest` |
| `INFLUXDB_V2_URL` | InfluxDB 2.x | `InfluxDBV2Test`, `InfluxDBV2VersionDetectionTest` |

The 2.x classes also read `INFLUXDB_V2_TOKEN` and `INFLUXDB_V2_ORG`, which default to the `it-token` and
`it-org` the server below is started with. 2.x has no `CREATE DATABASE`, so a class makes its database as a
bucket through the API; a bucket named without a slash has a virtual DBRP mapping, which is all InfluxQL needs.

Start InfluxDB 1.x and run the suite in the CI image, on the same Docker network so the image
reaches the server by name:

```sh
docker network create influx
docker run -d --rm --name influxdb --network influx influxdb:1.11
docker run --rm -v "$PWD":/app -w /app --network influx -e OTEL_SDK_DISABLED=true \
    -e INFLUXDB_V1_URL=http://influxdb:8086 \
    ghcr.io/ipsocode/hypervel/ci:8.4-latest composer test:integration
```

InfluxDB 2.x runs the same way, with the setup variables that create its organization, bucket and token:

```sh
docker run -d --rm --name influxdb2 --network influx \
    -e DOCKER_INFLUXDB_INIT_MODE=setup -e DOCKER_INFLUXDB_INIT_USERNAME=admin \
    -e DOCKER_INFLUXDB_INIT_PASSWORD=adminpassword -e DOCKER_INFLUXDB_INIT_ORG=it-org \
    -e DOCKER_INFLUXDB_INIT_BUCKET=it-bucket -e DOCKER_INFLUXDB_INIT_ADMIN_TOKEN=it-token \
    influxdb:2.7
docker run --rm -v "$PWD":/app -w /app --network influx -e OTEL_SDK_DISABLED=true \
    -e INFLUXDB_V2_URL=http://influxdb2:8086 \
    ghcr.io/ipsocode/hypervel/ci:8.4-latest composer test:integration
```

`.github/workflows/integration.yml` runs the same on pull requests, with the server as a service container.

php-cs-fixer and PHPStan are configured like `hypervel/components`:
`.php-cs-fixer.dist.php` loads its rules, verbatim, from
`.github/php-cs-fixer-rules.php`, and `phpstan.neon` carries its level,
analysis settings and framework extensions. Keep them in step when
`hypervel/components` changes, so a finding here is a finding there. Some of
the fixer's rules are risky — they change behaviour, not only formatting — so
run `composer test` after `composer lint:fix`, not just before committing. An
inline PHPStan ignore names its error and says why:
`@phpstan-ignore <identifier> (<reason>)`.

The conventions check, `.github/scripts/conventions.php`, enforces in CI the
rules a script can decide: the `Illuminate` or `Laravel` namespace anywhere in
PHP (docblocks and strings too), container array-access,
`@codeCoverageIgnore`, a bare `@phpstan-ignore-line`, a coverage gate below
100%, and raw SQL, `eval()`, `unserialize()`, shell commands, `sleep()` or
`exit` in shipped code. It also holds the names this package writes where the
application writes too: context keys `__influxdb.*`, cache and lock keys
`influxdb:*`, commands `influxdb:<verb>`, publish tags `influxdb-*`, env vars
`INFLUXDB_*` and `config/influxdb.php`. Each violation is reported on its
line. This package's settings are `.github/conventions.php`, and its exceptions
are exact, reasoned entries in the `allowed` list,
`'<path>' => [<exact number of hits>, '<why>']`: the raw-query rule meets the
raw API this package's InfluxQL builders and driver implement, safe because
values go through bindings and anything written as-is, such as a
`groupByTime()` duration, is checked first. The check fails once a count stops
matching either way, so a new raw call has to be argued for.

The automated review also runs `.github/scripts/review-scan.sh`, which flags what
a diff adds or removes. To see what it will flag on your branch:
`git diff origin/main...HEAD | bash .github/scripts/review-scan.sh`. The
review's instructions are the `pr-review` skill,
`.github/claude/skills/pr-review/SKILL.md`: ask Claude Code to follow it on your
branch to get the same review before you push.

CI (`.github/workflows/tests.yml`) runs for every pull request into `main`, each
job once the one it needs has passed: the conventions check, on the bare runner
in seconds (`.github/workflows/initial.yml`); code style, PHPStan and the suite
under the 100% coverage gate on PHP 8.4; then, side by side, PHPStan and the
suite on PHP 8.5, and the automated review. A push to a branch without a pull
request runs nothing, so open a draft pull request to get CI early; the
automated review runs once the pull request is marked ready, on each commit that
passes the conventions check and PHP 8.4. It keeps notes between pushes, so a
push is reviewed for what it changed, and for whatever else in the pull request
that reaches. A pull request into any other branch, such as an umbrella issue's
feature branch, runs nothing: CI and the automated review run on that branch's
own pull request into `main`, so run the checks locally before opening one.
`main` is protected: it changes only through a pull request that is up to date
with it, passes those checks, and has every review conversation resolved; a
change that leaves a line of `src/` uncovered fails its own pull request. The
review never blocks on what it finds; it comments, and each comment is a
conversation to resolve.

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
- Nothing on a request path creates a `WriteApi` or `QueryApi` per call.
  `InfluxDB2\Client` keeps a reference to every `WriteApi` it creates, so one
  per request is a leak for the life of the worker; `InfluxDBManager::writeApi()`
  memoises one per connection for that reason.
- No native `sleep()`/`usleep()`, blocking I/O or `exit()` on a request path.
- Hypervel APIs only, never the `Illuminate` namespace. Tests reach the
  container with `$app->get(...)`, never array access.
- New behaviour comes with a test. `@codeCoverageIgnore` is not a way past the
  coverage gate.

### Where the package keeps state

What the package holds for the life of a worker, and what resets it between
tests, is listed in
[Where the package keeps state](docs/internals.md#where-the-package-keeps-state);
keep that list current when a change adds state. `Testing\TestState` flushes
the static state after each test, so a new static needs a `flushState()`
called from it.

## Pull requests

Fill in the pull request template and label the pull request with the type it
ticks. There is no changelog file: each release's notes are generated from the
titles of the pull requests it contains, grouped by those labels
(`.github/release.yml`), so write the title for someone reading the release. A
pull request labelled `breaking-change` makes the next release at least a minor
version.

A change a consumer will observe, such as a config key, the InfluxQL a query
produces or what a driver reads back, goes in the template's *Behaviour change*
section, with the documentation update it needs (`README.md` or `docs/`) in
the same pull request.

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
every path `.gitattributes` marks `export-ignore`: `.github/`,
`docs/.mcp.json`, the tests, the workbench and the development configs. A new
top-level file or directory ships unless it is added there;
`git archive HEAD | tar -t` lists what would.

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
