<!-- Imported from ipsocode/hypervel-packages github/claude/AGENTS.md. Edit it there. -->

# hypervel-influxdb

`ipsocode/hypervel-influxdb` is a Composer package for Hypervel 0.4 (`hypervel/components`), which
runs PHP 8.4+ on Swoole: one long-lived worker serves many requests at once, as coroutines.

- `README.md` says what the package does.
- `CONTRIBUTING.md` has the setup, the checks and this package's own rules. Where it is more
  specific than this file, it wins.

## Hypervel, not Laravel

Hypervel's APIs carry Laravel's names, but they are different classes. Before you use one, find
it in the installed framework: the source is in `vendor/hypervel/components/src/*/src/` and the
docs in `vendor/hypervel/components/src/docs/`. Do not assume Laravel's API.

For package conventions (discovery, providers, config merging, publishing, test state cleanup),
read the relevant section of `vendor/hypervel/components/src/docs/packages.md` first. In CI the
framework is restored from the tests' cache; when `vendor/` is absent, say what could not be
checked rather than assume Laravel's API.

- `Illuminate\` and `Laravel\` anywhere in the package, docblocks and strings included, fail the
  conventions check.
- Read config with `$app->get('config')`, never through container array-access.

## Coroutine safety

What would be per-request under PHP-FPM is shared by every request a worker serves:

- A new `static` property needs a `flushState()`, reset from `src/Testing/TestState.php`.
- Request state lives in `Hypervel\Context\CoroutineContext`, never in a static or a container
  rebinding.
- No native `sleep()`/`usleep()`, blocking I/O, shell command or `exit()` on a request path.
- Worker-global state is set at boot only.

## Checks

A change is done when all four of these pass. CI runs them on every pull request.

| Command | Runs |
|---|---|
| `composer conventions` | The conventions check. It is plain PHP and needs no install. |
| `composer lint` | Code style |
| `composer analyse` | PHPStan at level 5 |
| `composer test:coverage` | The suite, failing below 100% line coverage |

Run them in CI's image, `ghcr.io/ipsocode/hypervel/ci:8.4-latest` (or `8.5-latest`), not on the
host. It has Swoole, Redis and PCOV, and `CONTRIBUTING.md` gives the `docker run` for it.

- Never lower the coverage gate or add `@codeCoverageIgnore`: cover the line instead.
- A PHPStan ignore gives its identifier and its reason.
- The conventions check also holds the names the package writes where the application writes
  too to the package's own prefix: context keys, cache and lock keys, commands, publish tags,
  env vars and config names. An exception is an exact, reasoned `allowed` entry in
  `.github/conventions.php`.

## Shared files

A file whose first lines say "Imported from ipsocode/hypervel-packages" is a copy: most of
`.github/`, including this brief and the pr-review skill in `.github/claude/`. A change to one
belongs in that repository, and a change made here is overwritten by the next import. This
package's own files there are `.github/conventions.php` and `.github/review/`.

The package keeps no `.claude/`, and a release leaves `.github/` out. The automated review
installs this brief and the skill on its runner, for the review only.

## Pull requests

- `main` changes only through a pull request. It has to be up to date with `main`, pass
  `initial / Conventions`, `PHP 8.4` and `claude / review`, and have every review conversation
  resolved.
- A change to a setup file also needs the maintainer's approval. `.github/CODEOWNERS` lists the
  setup files.
- A draft pull request gets CI, and the automated review runs once it is marked ready. To review
  a branch before you push it, follow `.github/claude/skills/pr-review/SKILL.md`.
- Fill in the pull request template. Label a breaking change `breaking-change`: the label, not
  the diff, decides the next version.
- This repository stands alone. Commit messages, pull request titles and descriptions, code
  comments and docs name no other repository:
  - no other ipsocode/hypervel-* package, and none of its issues or pull requests;
  - not ipsocode/hypervel-packages, which only coordinates the packages.

  Explain a change by what it does here, and refer to this repository's issues and pull requests
  as `#<n>`. The dependencies in `composer.json`, Hypervel's included, may be named. The import
  marker on a shared file is the tooling's, and the one exception.
