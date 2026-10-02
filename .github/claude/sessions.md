<!-- Imported from ipsocode/hypervel-packages github/claude/sessions.md. Edit it there. -->

# Run PHP in the CI image

Run every `php`, `composer`, `vendor/bin/*` and `testbench` command in the CI image,
`ghcr.io/ipsocode/hypervel/ci:8.4-latest`, never with a PHP on the host. The suite runs on
Swoole and its coverage gate needs PCOV, which a host PHP rarely has, so a result from one
says nothing about CI. `git` runs on the host.

- **In a Claude Code cloud session** the environment provides `ci` (`command -v ci` finds
  it), which runs a command in that image with the current directory mounted. Run every
  PHP command through it, from the root of the checkout:
  `ci composer install --prefer-dist -n -o`, then `ci composer test:coverage`. The VM's own
  PHP 8.3 has neither Swoole nor PCOV. If `ci` cannot run, stop and report why rather than
  fall back to that PHP.
- **Anywhere else**, start the image with the `docker run` in `CONTRIBUTING.md`.
