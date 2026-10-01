## Summary

Closes #

## Type

- [ ] Bug / coroutine-safety
- [ ] Performance
- [ ] Conformance (Hypervel package conventions)
- [ ] Documentation
- [ ] Feature

## Behaviour change

## Coroutine safety

Hypervel runs in long-lived Swoole workers serving concurrent requests via coroutines.

- [ ] No new `static` property — or it has `flushState()` and is reset from the package's `src/Testing/TestState.php`
- [ ] Request-scoped state lives in `Hypervel\Context\CoroutineContext`, never in statics or container rebinds
- [ ] No native `sleep()`/`usleep()`, blocking I/O, shell command or `exit()` on a request path
- [ ] Hypervel APIs only, no `Illuminate\*`; `$app->get('config')`, never container array-access
- [ ] Worker-global state is set at boot only, or documented as boot-only
- [ ] The package's own rules in CONTRIBUTING.md hold

## Tests

- [ ] `composer test:coverage` passes the `--min=100` gate
- [ ] New behaviour has a test; no `@codeCoverageIgnore` added to pass the gate
- [ ] Nothing generated is committed (`vendor/`, `tests/coverage/`, `tests/.cache/`)
- [ ] No real credentials anywhere in the diff or the description
