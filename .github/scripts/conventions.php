<?php

declare(strict_types=1);

// Imported from ipsocode/hypervel-packages github/scripts/conventions.php. Edit it there.

/*
 * The conventions check: the review rules a script can decide, enforced by the
 * required PHP 8.4 check instead of by eye.
 *
 *     php .github/scripts/conventions.php [<package root>]
 *
 * initial.yml runs it on the bare runner, ahead of tests.yml's container jobs,
 * so it needs nothing but PHP itself: no install, no extension beyond
 * tokenizer, SimpleXML and JSON, and PHP 8.3, the runner's own, rather than the
 * 8.4 the package requires. `composer conventions` runs it locally. It prints
 * one line per violation, `path:line rule — what to do`, as an annotation on
 * that line when it runs in GitHub Actions, and exits 1 if there is any.
 *
 * Framework conformance, anywhere in the package's PHP
 *   namespace               Illuminate\ or Laravel\, or a banned_namespaces root,
 *                           comments and strings included: a docblock type or a
 *                           class-string naming a class that does not exist fails
 *                           silently at runtime
 *   dependency              an illuminate/* or laravel/* package in composer.json
 *   container-array-access  $app['config'] instead of $app->get('config')
 * The coverage gate
 *   coverage-ignore         @codeCoverageIgnore in src/
 *   phpstan-ignore          @phpstan-ignore-line or -next-line in src/, which ignore
 *                           every error on the line; @phpstan-ignore names its error
 *   coverage-gate           --min=100 in composer.json and the workflows,
 *                           MIN_COVERAGE at 100, and phpunit.xml's <source>
 *                           measuring all of src/
 * Shipped code: every directory but tests/ and workbench/
 *   raw-sql                 the DB:: facade's SQL methods, any …Raw() method, and a
 *                           connection's raw(), statement(), affectingStatement()
 *                           or unprepared()
 *   eval-unserialize        eval() and unserialize()
 *   shell                   shell_exec(), exec(), system(), passthru(),
 *                           proc_open(), popen() and the backtick operator
 *   sleep-exit              sleep(), usleep(), time_nanosleep(),
 *                           time_sleep_until(), exit and die
 *   banned-function         a function named by a banned_functions prefix
 * Names written where the application writes too, in src/ and config/
 *   context-key             __<slug>.segment.segment
 *   store-key               <slug>:segment:segment, for cache, lock and
 *                           rate-limit keys
 *   command-name            <slug>:kebab-verb
 *   publish-tag             <slug>-group
 *   env-var                 <SLUG>_NAME
 *   config-name             config/<slug>.php, merged under the key <slug>
 *   facade-accessor         a short lowercase container abstract, never a
 *                           class-string
 *
 * A collision in those shared namespaces is silent: nothing errors, and the two
 * writers read each other's values. The shapes are taken from hypervel/components
 * 0.4 (`__auth.resolver`, `hypervel:schedule:interrupt`, `horizon:clear-metrics`,
 * `telescope-config`, `HORIZON_PREFIX`), not invented.
 *
 * Text rules read the raw file. Call rules read tokens, so a comment or a string
 * that mentions a function cannot match: a name is a call when `(` follows it,
 * and methods (->, ?->, ::) are skipped, except the DB:: facade and the query
 * builder's …Raw() family.
 *
 * The package's settings are .github/conventions.php. An exception to one of the
 * first ten rules is explicit and exact: an `allowed` entry records how many hits
 * a file has and why. More hits than recorded is a new violation and fewer is a
 * stale entry, and both fail, the way PHPStan's reportUnmatchedIgnoredErrors
 * treats an ignore that no longer matches. The coverage gate and the naming rules
 * take no exceptions: a lowered gate or a name off the convention is fixed.
 *
 * .github/ is a dot-directory, and every dot-directory is skipped, so neither this
 * file nor the settings, which have to name what they ban, are ever scanned.
 */
final class Conventions
{
    /**
     * Where the package keeps its settings, relative to its root.
     */
    private const string SETTINGS_FILE = '.github/conventions.php';

    /**
     * The settings that file may set, with their defaults. `slug` has none.
     *
     * @var array<string, mixed>
     */
    private const array SETTINGS = [
        'slug' => null,
        'excluded' => [],
        'banned_namespaces' => [],
        'banned_functions' => [],
        'coverage_excludes' => [],
        'allowed' => [],
    ];

    /**
     * The rules an `allowed` entry can name.
     *
     * @var list<string>
     */
    private const array ALLOWABLE = [
        'namespace',
        'dependency',
        'container-array-access',
        'coverage-ignore',
        'phpstan-ignore',
        'raw-sql',
        'eval-unserialize',
        'shell',
        'sleep-exit',
        'banned-function',
    ];

    /**
     * The `DB::` facade's methods that take SQL.
     *
     * @var list<string>
     */
    private const array RAW_SQL_FACADE = [
        'raw', 'select', 'selectOne', 'selectFromWriteConnection', 'selectResultSets', 'scalar', 'cursor',
        'insert', 'update', 'delete', 'statement', 'affectingStatement', 'unprepared',
    ];

    /**
     * The connection methods that take SQL and mean it on any object. `select()`,
     * `insert()`, `update()` and `delete()` are left out: on a query builder they
     * take columns and values, not SQL.
     *
     * @var list<string>
     */
    private const array RAW_SQL_METHODS = ['raw', 'statement', 'affectingStatement', 'unprepared'];

    /**
     * Directory names skipped wherever they occur: dependencies and generated output.
     *
     * @var list<string>
     */
    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'coverage', 'storage'];

    /**
     * What was found, in the order found: [path, line or 0, rule, message].
     *
     * @var list<array{string, int, string, string}>
     */
    private array $violations = [];

    /**
     * The settings, validated, with every default filled in.
     *
     * @var array{slug: string, excluded: list<string>, banned_namespaces: array<string, string>, banned_functions: array<string, string>, coverage_excludes: list<string>, allowed: array<string, array<string, array{int, string}>>}
     */
    private array $settings;

    /**
     * @var null|list<string>
     */
    private ?array $files = null;

    /**
     * Each file's tokens, read once for all the rules that need them.
     *
     * @var array<string, list<array{?int, string, int}>>
     */
    private array $tokenCache = [];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * Run every rule and print what broke. 0 when the package conforms, 1 when not.
     */
    public function run(): int
    {
        if ($this->loadSettings()) {
            $this->checkNamespaces();
            $this->checkDependencies();
            $this->checkContainerAccess();
            $this->checkCoverageIgnores();
            $this->checkPhpstanIgnores();
            $this->checkCoverageGate();
            $this->checkRawSql();
            $this->checkCalls();
            $this->checkNames();
        }

        return $this->report();
    }

    /**
     * Read and validate .github/conventions.php. False when it is missing or too
     * broken to run the rules against.
     */
    private function loadSettings(): bool
    {
        $file = $this->root . '/' . self::SETTINGS_FILE;

        if (! is_file($file)) {
            $this->violation(self::SETTINGS_FILE, 0, 'settings', "Missing: this package's settings for the check. Create it, returning at least ['slug' => '<the package name without hypervel->'].");

            return false;
        }

        $settings = (static fn (string $file): mixed => require $file)($file);

        if (! is_array($settings)) {
            $this->violation(self::SETTINGS_FILE, 0, 'settings', 'Has to return an array of settings.');

            return false;
        }

        $valid = true;

        foreach (array_diff(array_keys($settings), array_keys(self::SETTINGS)) as $key) {
            $this->settingsProblem((string) $key, "There is no setting '{$key}'. The settings are " . implode(', ', array_keys(self::SETTINGS)) . '.');
            $valid = false;
        }

        $settings += self::SETTINGS;

        if (! is_string($settings['slug']) || preg_match('/^[a-z][a-z0-9]*$/', $settings['slug']) !== 1) {
            $this->settingsProblem('slug', "'slug' has to be the package's name without hypervel-, in lowercase letters and digits.");
            $valid = false;
        }

        foreach (['excluded', 'coverage_excludes'] as $key) {
            if (! is_array($settings[$key]) || ! array_is_list($settings[$key]) || array_filter($settings[$key], fn (mixed $path) => ! is_string($path) || $this->normalise($path) === '') !== []) {
                $this->settingsProblem($key, "'{$key}' has to be a list of paths under the package root.");
                $valid = false;

                continue;
            }

            $settings[$key] = array_map($this->normalise(...), $settings[$key]);

            foreach ($settings[$key] as $path) {
                if (! file_exists($this->root . '/' . $path)) {
                    $this->settingsProblem($path, "'{$key}' names {$path}, and there is no such path.");
                }
            }
        }

        foreach (['banned_namespaces', 'banned_functions'] as $key) {
            if (! is_array($settings[$key]) || array_filter(array_keys($settings[$key]), fn (int|string $name) => ! is_string($name) || trim($name, '\ ') === '') !== [] || array_filter($settings[$key], fn (mixed $instead) => ! is_string($instead) || trim($instead) === '') !== []) {
                $this->settingsProblem($key, "'{$key}' has to map each banned name to what to use instead.");
                $valid = false;
            }
        }

        if (! is_array($settings['allowed'])) {
            $this->settingsProblem('allowed', "'allowed' has to map rules to their exceptions: ['<rule>' => ['<path>' => [<exact number of hits>, '<why>']]].");

            return false;
        }

        foreach ($settings['allowed'] as $rule => $entries) {
            if (! in_array($rule, self::ALLOWABLE, true)) {
                $this->settingsProblem((string) $rule, "'allowed' names the rule '{$rule}', which takes no exceptions. The rules that do: " . implode(', ', self::ALLOWABLE) . '.');
                $valid = false;

                continue;
            }

            if (! is_array($entries)) {
                $this->settingsProblem($rule, "allowed['{$rule}'] has to map paths to [<exact number of hits>, '<why>'].");
                $valid = false;

                continue;
            }

            foreach ($entries as $path => $entry) {
                if (! is_array($entry) || count($entry) !== 2 || ! is_int($entry[0] ?? null) || $entry[0] < 1 || ! is_string($entry[1] ?? null) || trim($entry[1]) === '') {
                    $this->settingsProblem((string) $path, "allowed['{$rule}']['{$path}'] has to be [<exact number of hits, at least 1>, '<why>'].");
                    $valid = false;
                }
            }
        }

        if ($valid) {
            $settings['banned_namespaces'] = array_combine(
                array_map(fn (string $name) => trim($name, '\ '), array_keys($settings['banned_namespaces'])),
                $settings['banned_namespaces'],
            );
            $this->settings = $settings;
        }

        return $valid;
    }

    /**
     * `Illuminate\` and `Laravel\` anywhere in the package's PHP: code, strings and
     * comments alike. A `use` of a class that does not exist fails as soon as it
     * runs; a docblock type or a class-string does not, and a `class_exists()`
     * check is simply false, forever.
     */
    private function checkNamespaces(): void
    {
        $banned = [
            'Illuminate' => 'the Hypervel\ equivalent',
            'Laravel' => 'the Hypervel\ equivalent',
        ] + $this->settings['banned_namespaces'];

        $roots = implode('|', array_map(fn (string $root) => preg_quote($root, '/'), array_keys($banned)));

        $this->conform(
            'namespace',
            $this->textHits($this->packageFiles(), '/\b(' . $roots . ')\\\+[A-Z]/'),
            fn (string $root) => "`{$root}\\` is a namespace this package does not use. Instead: {$banned[$root]}.",
        );
    }

    /**
     * No `illuminate/*` or `laravel/*` package in composer.json: the hypervel/*
     * packages are the framework.
     */
    private function checkDependencies(): void
    {
        $this->conform(
            'dependency',
            is_file($this->root . '/composer.json') ? $this->textHits(['composer.json'], '/"((?:illuminate|laravel)\/[^"]+)"\s*:/i') : [],
            fn (string $package) => "`{$package}` is a Laravel package. Depend on the hypervel/* package that provides it.",
        );
    }

    /**
     * The container is read through its methods, `$app->get('config')`, never as
     * an array, `$app['config']`.
     */
    private function checkContainerAccess(): void
    {
        $this->conform(
            'container-array-access',
            $this->tokenHits($this->packageFiles(), fn (array $tokens, int $i) => $this->isContainerArrayAccess($tokens, $i)),
            fn () => "Reads the container as an array. Use its methods: \$app->get('config').",
        );
    }

    /**
     * No `@codeCoverageIgnore` in src/: a line the suite cannot reach gets a test,
     * or is deleted, rather than being hidden from the 100% gate.
     */
    private function checkCoverageIgnores(): void
    {
        $this->conform(
            'coverage-ignore',
            $this->textHits($this->sourceFiles(), '/@codeCoverageIgnore/'),
            fn () => '@codeCoverageIgnore hides code from the coverage gate. Cover it with a test, or delete it if nothing can reach it.',
        );
    }

    /**
     * No `@phpstan-ignore-line` or `@phpstan-ignore-next-line` in src/: each one
     * ignores every error on its line, including ones nobody has seen yet. The
     * targeted form names its error, and phpstan.neon's reportIgnoresWithoutComments
     * makes it carry a reason too.
     */
    private function checkPhpstanIgnores(): void
    {
        $this->conform(
            'phpstan-ignore',
            $this->textHits($this->sourceFiles(), '/@phpstan-ignore-(?:next-)?line\b/'),
            fn () => 'Ignores every PHPStan error on the line. Name the one meant: @phpstan-ignore <identifier> (<reason>).',
        );
    }

    /**
     * The coverage gate stays at 100%: `--min=100` in composer.json's scripts,
     * MIN_COVERAGE at 100 in the workflows, and phpunit.xml's `<source>` measuring
     * all of src/. Each of the three can lower the bar alone, and a run under a
     * lowered bar still says OK.
     */
    private function checkCoverageGate(): void
    {
        $rule = 'coverage-gate';

        // composer.json: test:coverage runs with --min=100, and no script with less.
        $composer = is_file($this->root . '/composer.json')
            ? json_decode((string) file_get_contents($this->root . '/composer.json'), true)
            : null;

        if (! is_array($composer)) {
            $this->violation('composer.json', 0, $rule, 'Missing, or not valid JSON.');
        } else {
            $gated = false;

            foreach ($composer['scripts'] ?? [] as $name => $commands) {
                foreach ((array) $commands as $command) {
                    preg_match_all('/--min=(\S+)/', (string) $command, $matches);

                    foreach ($matches[1] as $minimum) {
                        $gated = $gated || $name === 'test:coverage';

                        if ($minimum !== '100') {
                            $this->violation('composer.json', $this->lineOf('composer.json', "--min={$minimum}"), $rule, "The {$name} script runs with --min={$minimum}. The gate is 100.");
                        }
                    }
                }
            }

            if (! $gated) {
                $this->violation('composer.json', $this->lineOf('composer.json', '"test:coverage"'), $rule, 'The test:coverage script has to run the suite with --min=100.');
            }
        }

        // The workflows: MIN_COVERAGE is 100 wherever one sets it, and every --min is
        // 100 or reads it.
        $settings = 0;
        $flags = 0;

        foreach (glob($this->root . '/.github/workflows/*.y*ml') ?: [] as $workflow) {
            $path = '.github/workflows/' . basename($workflow);

            foreach (file($workflow) ?: [] as $index => $text) {
                preg_match_all('/\bMIN_COVERAGE:\s*[\'"]?([^\'"\s#]+)/', $text, $matches);

                foreach ($matches[1] as $value) {
                    ++$settings;

                    if ($value !== '100') {
                        $this->violation($path, $index + 1, $rule, "MIN_COVERAGE is {$value}. The gate is 100.");
                    }
                }

                preg_match_all('/--min=(\S+)/', $text, $matches);

                foreach ($matches[1] as $value) {
                    ++$flags;

                    if (preg_match('/^(?:100|"?\$\{?MIN_COVERAGE\}?"?)$/', $value) !== 1) {
                        $this->violation($path, $index + 1, $rule, "The suite runs with --min={$value}. Use --min=100, or --min=\"\${MIN_COVERAGE}\" with MIN_COVERAGE at 100.");
                    }
                }
            }
        }

        if ($settings === 0) {
            $this->violation('.github/workflows', 0, $rule, 'No workflow sets MIN_COVERAGE, so no release is gated on coverage.');
        }

        if ($flags === 0) {
            $this->violation('.github/workflows', 0, $rule, 'No workflow runs the suite with --min, so MIN_COVERAGE gates nothing.');
        }

        // phpunit.xml: <source> measures src/, less only what coverage_excludes allows.
        libxml_use_internal_errors(true);
        $phpunit = is_file($this->root . '/phpunit.xml') ? simplexml_load_file($this->root . '/phpunit.xml') : false;

        if ($phpunit === false) {
            $this->violation('phpunit.xml', 0, $rule, 'Missing, or not valid XML.');

            return;
        }

        $line = $this->lineOf('phpunit.xml', '<source');
        $include = $this->sourcePaths($phpunit->source->include ?? null);
        $exclude = $this->sourcePaths($phpunit->source->exclude ?? null);
        $allowed = $this->settings['coverage_excludes'];
        sort($allowed);

        if ($include !== ['src']) {
            $this->violation('phpunit.xml', $line, $rule, sprintf('<source> includes %s. It has to include src/, all of its PHP, and nothing else.', $this->listing($include)));
        }

        if ($exclude !== $allowed) {
            $this->violation('phpunit.xml', $line, $rule, sprintf("<source> excludes %s. It may exclude only what 'coverage_excludes' in %s lists: %s.", $this->listing($exclude), self::SETTINGS_FILE, $this->listing($allowed)));
        }
    }

    /**
     * No raw SQL in shipped code: the `DB::` facade's SQL methods, the query
     * builder's `…Raw()` family, and `->raw()`, `->statement()`,
     * `->affectingStatement()` and `->unprepared()` on a connection. The query
     * builder binds every value it is given; raw SQL is where a value gets
     * interpolated instead, and where an identifier taken from input ends up.
     */
    private function checkRawSql(): void
    {
        $this->conform(
            'raw-sql',
            $this->tokenHits($this->shippedFiles(), fn (array $tokens, int $i) => $this->isRawSql($tokens, $i)),
            fn (string $method) => "`{$method}()` runs raw SQL. Build the query with the query builder, which binds its values.",
        );
    }

    /**
     * The calls shipped code never makes: code execution, shell, sleep and exit,
     * and whatever this package's settings ban.
     */
    private function checkCalls(): void
    {
        $files = $this->shippedFiles();

        $this->conform(
            'eval-unserialize',
            $this->calls($files, fn (string $name) => in_array($name, ['eval', 'unserialize'], true)),
            fn (string $name) => $name === 'eval'
                ? '`eval()` runs a string as PHP.'
                : '`unserialize()` builds whatever objects a string describes. Decode data as data: json_decode().',
        );

        $this->conform(
            'shell',
            $this->calls($files, fn (string $name) => in_array($name, ['shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', '`'], true)),
            fn (string $name) => ($name === '`' ? 'The backtick operator' : "`{$name}()`") . ' runs a shell command.',
        );

        // A worker serves many requests at once as coroutines. A native sleep blocks
        // every one of them wherever Swoole's runtime hooks do not cover it, and exit
        // ends the worker along with all of them.
        $this->conform(
            'sleep-exit',
            $this->calls($files, fn (string $name) => in_array($name, ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until', 'exit', 'die'], true)),
            fn (string $name) => in_array($name, ['exit', 'die'], true)
                ? "`{$name}` ends the worker and every request it is serving. Throw instead."
                : "`{$name}()` blocks the worker's other coroutines wherever Swoole's hooks do not reach. Wait with Hypervel\\Coroutine\\Coroutine::sleep().",
        );

        $banned = $this->settings['banned_functions'];
        $prefix = fn (string $name) => array_values(array_filter(array_keys($banned), fn (string $prefix) => str_starts_with($name, strtolower($prefix))))[0] ?? null;

        $this->conform(
            'banned-function',
            $this->calls($files, fn (string $name) => $prefix($name) !== null),
            fn (string $name) => sprintf('`%s()` is one of the `%s*()` functions, which this package does not use. Instead: %s.', $name, $prefix($name), $banned[$prefix($name)]),
        );
    }

    /**
     * The names this package writes into namespaces the application also owns: a
     * coroutine context key, a cache key, a console command, a publish tag, an env
     * var and its config. There is no allowlist on purpose: a name that breaks the
     * convention is renamed, not recorded.
     */
    private function checkNames(): void
    {
        $slug = $this->settings['slug'];
        $files = array_values(array_filter(
            $this->packageFiles(),
            fn (string $path) => str_starts_with($path, 'src/') || str_starts_with($path, 'config/'),
        ));

        // `__<slug>.<snake>.<snake>`: the `__` reserves the key from application keys,
        // and the first segment says which package owns it. Three shapes: a literal
        // handed straight to CoroutineContext, a dotted constant (a package that keeps
        // its keys in one registry declares them there), and a `*ContextKey()`
        // accessor returning a literal.
        $this->names('context-key', $files, [
            '/(?:CoroutineContext|Context)::(?:get|set|has|forget|getOrSet|destroy)\(\s*[\'"]([^\'"$]+)[\'"]/',
            '/const\s+(?:string\s+)?[A-Z][A-Z0-9_]*\s*=\s*[\'"]((?:__)?[a-z][a-z0-9_]*\.[^\'"$]*)[\'"]/',
            '/function\s+\w*ContextKey\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s+[\'"]([^\'"$]+)[\'"]/',
        ], '/^__' . $slug . '(\.[a-z0-9_*]+)*\.?$/', "__{$slug}.segment.segment");

        // `<slug>:<segment>:<segment>`: cache, lock and rate-limit keys share one flat
        // store, colon-delimited the way `hypervel:schedule:interrupt` is.
        $this->names('store-key', $files, [
            '/->by\(\s*[\'"]([^\'"$]+)[\'"]/',
            '/->lock\(\s*[\'"]([^\'"$]+)[\'"]/',
            '/Cache::(?:get|put|add|forever|forget|remember|rememberForever|pull|has|increment|decrement)\(\s*[\'"]([^\'"$]+)[\'"]/',
        ], '/^' . $slug . '(:[a-z0-9_-]+)+:?$/', "{$slug}:segment:segment");

        // `<slug>:<kebab-verb>`, exactly two segments: of roughly 150 command
        // signatures in components 0.4, none has three.
        $this->names('command-name', $files, [
            '/signature\s*=\s*[\'"]([a-z0-9:_-]+)/',
        ], '/^' . $slug . ':[a-z0-9]+(-[a-z0-9]+)*$/', "{$slug}:kebab-verb");

        // `<slug>-config`, `<slug>-migrations`: the tag is what a user types at
        // `vendor:publish --tag=`. A `{$var}` segment is allowed, since a tag built in
        // a loop over a fixed map is still statically prefixed.
        $this->names('publish-tag', $files, [
            '/publishes(?:Migrations)?\(\s*\[[^\]]*\]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
        ], '/^' . $slug . '-(?:[a-z0-9]+|\{\$\w+\})(?:-(?:[a-z0-9]+|\{\$\w+\}))*$/', "{$slug}-group");

        // `<SLUG>_<SCREAMING_SNAKE>`: one prefix per package, the way a component's
        // config reads only `HORIZON_*`.
        $this->names('env-var', $files, [
            '/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/',
        ], '/^' . strtoupper($slug) . '_[A-Z0-9_]+$/', strtoupper($slug) . '_NAME');

        // config/<slug>.php merged under the key <slug>: the file a user publishes, the
        // key they override and the package they installed share one name. The key is
        // matched without newlines, since config headers talk about mergeConfigFrom()
        // and an unbounded match runs from the prose into the first quoted key.
        $this->names('config-name', $files, [
            '/mergeConfigFrom\(\s*[^,)\n]+,\s*[\'"]([^\'"]+)[\'"]/',
        ], '/^' . $slug . '$/', $slug);

        foreach ($files as $path) {
            if (preg_match('#^config/([^/]+)\.php$#', $path, $match) === 1 && $match[1] !== $slug) {
                $this->violation($path, 0, 'config-name', "config/{$match[1]}.php does not match the convention `config/{$slug}.php`. Rename it.");
            }
        }

        // A short lowercase container abstract, never a class-string: every upstream
        // facade returns one (`return 'redis';`), which is what makes the binding
        // addressable from config and from app('…').
        $this->names('facade-accessor', $files, [
            '/getFacadeAccessor\(\)\s*:\s*\??string\s*\{\s*return\s+([^;]+);/',
        ], '/^\'[a-z0-9]+(\.[a-z0-9]+)*\'$/', "'{$slug}'");
    }

    /**
     * Compare a rule's hits with its exceptions and record every hit that does not
     * fit. A file without an exception has to have no hits, and a file with one
     * exactly the number it records: more is a new violation, fewer a stale entry.
     *
     * @param array<string, list<array{int, string}>> $hits path => [line, what matched]
     * @param Closure(string): string $message what matched => what to do about it
     */
    private function conform(string $rule, array $hits, Closure $message): void
    {
        $allowed = $this->settings['allowed'][$rule] ?? [];

        foreach ($hits as $path => $found) {
            $expected = $allowed[$path][0] ?? 0;

            if (count($found) === $expected) {
                continue;
            }

            foreach ($found as [$line, $what]) {
                $this->violation($path, $line, $rule, $message($what));
            }

            if ($expected > 0) {
                $this->settingsProblem($path, sprintf(
                    count($found) > $expected
                        ? "%1\$s: found %2\$d, and allowed['%3\$s'] records %4\$d. Fix the new ones, or record %2\$d if its reason covers them."
                        : "%1\$s: found %2\$d, and allowed['%3\$s'] records %4\$d. Record %2\$d.",
                    $path,
                    count($found),
                    $rule,
                    $expected,
                ), $rule);
            }
        }

        foreach ($allowed as $path => $entry) {
            if (! isset($hits[$path])) {
                $this->settingsProblem($path, sprintf("%s: found none, and allowed['%s'] records %d. Remove the entry.", $path, $rule, $entry[0]), $rule);
            }
        }
    }

    /**
     * Record every capture of the patterns in the given files that does not match
     * the convention, on the line where it starts.
     *
     * @param list<string> $files
     * @param list<string> $patterns each captures the name in its first group
     */
    private function names(string $rule, array $files, array $patterns, string $convention, string $shape): void
    {
        foreach ($files as $path) {
            $source = (string) file_get_contents($this->root . '/' . $path);

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[1] as [$name, $offset]) {
                    $name = trim($name);

                    if (preg_match($convention, $name) !== 1) {
                        $line = substr_count($source, "\n", 0, $offset) + 1;
                        $this->violation($path, $line, $rule, "`{$name}` does not match the convention `{$shape}`. Rename it.");
                    }
                }
            }
        }
    }

    /**
     * The given files' lines that match a pattern, once per match, with the first
     * group, or the whole match when there is none.
     *
     * @param list<string> $files
     * @return array<string, list<array{int, string}>>
     */
    private function textHits(array $files, string $pattern): array
    {
        $hits = [];

        foreach ($files as $path) {
            foreach (file($this->root . '/' . $path) ?: [] as $index => $line) {
                preg_match_all($pattern, $line, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $hits[$path][] = [$index + 1, $match[1] ?? $match[0]];
                }
            }
        }

        return $hits;
    }

    /**
     * The tokens of the given files where a matcher fires, with the token's text.
     *
     * @param list<string> $files
     * @param callable(list<array{?int, string, int}>, int): bool $matches
     * @return array<string, list<array{int, string}>>
     */
    private function tokenHits(array $files, callable $matches): array
    {
        $hits = [];

        foreach ($files as $path) {
            $tokens = $this->tokens($path);

            foreach (array_keys($tokens) as $i) {
                if ($matches($tokens, $i)) {
                    $hits[$path][] = [$tokens[$i][2], $tokens[$i][1]];
                }
            }
        }

        return $hits;
    }

    /**
     * Where the given files call a banned global function, with its lowercase name.
     *
     * `eval`, `exit` and `die` are language constructs with tokens of their own, so
     * they match with or without `(`. The backtick operator runs a shell command
     * and is passed to $banned as `` ` ``.
     *
     * @param list<string> $files
     * @param callable(string): bool $banned receives a lowercase name without a leading `\`
     * @return array<string, list<array{int, string}>>
     */
    private function calls(array $files, callable $banned): array
    {
        $hits = [];

        foreach ($files as $path) {
            $tokens = $this->tokens($path);
            $backticks = 0;

            foreach ($tokens as $i => [$id, $text, $line]) {
                if ($id === T_EVAL || $id === T_EXIT) {
                    $name = strtolower($text);
                } elseif ($id === null && $text === '`') {
                    // A command is a pair of backticks; count the opening one.
                    $name = $backticks++ % 2 === 0 ? '`' : null;
                } else {
                    $name = $this->calledFunction($tokens, $i);
                }

                if ($name !== null && $banned($name)) {
                    $hits[$path][] = [$line, $name];
                }
            }
        }

        return $hits;
    }

    /**
     * The lowercase name of the global function token $i calls, or null.
     *
     * A call is a bare or fully qualified name followed by `(` that is not a method
     * (`->`, `?->`, `::`), a declaration (`function`, `const`) or a class (`new`).
     *
     * @param list<array{?int, string, int}> $tokens
     */
    private function calledFunction(array $tokens, int $i): ?string
    {
        [$id, $text] = $tokens[$i];

        if (($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED) || ! $this->isChar($tokens, $i + 1, '(')) {
            return null;
        }

        $before = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NEW];

        if (isset($tokens[$i - 1]) && in_array($tokens[$i - 1][0], $before, true)) {
            return null;
        }

        $name = strtolower(ltrim($text, '\\'));

        return str_contains($name, '\\') ? null : $name;
    }

    /**
     * Whether token $i starts an SQL call: a `DB::` facade SQL method, any `…Raw()`
     * method, or a connection's SQL method reached through `->`.
     *
     * @param list<array{?int, string, int}> $tokens
     */
    private function isRawSql(array $tokens, int $i): bool
    {
        if (! $this->isToken($tokens, $i, T_STRING) || ! $this->isChar($tokens, $i + 1, '(')) {
            return false;
        }

        $method = $tokens[$i][1];
        $raw = str_ends_with($method, 'Raw');

        if ($this->isToken($tokens, $i - 1, T_OBJECT_OPERATOR) || $this->isToken($tokens, $i - 1, T_NULLSAFE_OBJECT_OPERATOR)) {
            return $raw || $this->isOneOf($method, self::RAW_SQL_METHODS);
        }

        if (! $this->isToken($tokens, $i - 1, T_DOUBLE_COLON)) {
            return false;
        }

        $class = $tokens[$i - 2] ?? [null, ''];
        $facade = in_array($class[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && str_ends_with('\\' . $class[1], '\DB');

        return $raw || ($facade && $this->isOneOf($method, self::RAW_SQL_FACADE));
    }

    /**
     * Whether token $i starts reading the container as an array: `$app[…]`,
     * `$container[…]`, `$this->app[…]`, `$this->container[…]` or `app()[…]`.
     *
     * @param list<array{?int, string, int}> $tokens
     */
    private function isContainerArrayAccess(array $tokens, int $i): bool
    {
        if ($this->isToken($tokens, $i, T_VARIABLE, '$app', '$container')) {
            return $this->isChar($tokens, $i + 1, '[');
        }

        if ($this->isToken($tokens, $i, T_VARIABLE, '$this')) {
            return $this->isToken($tokens, $i + 1, T_OBJECT_OPERATOR)
                && $this->isToken($tokens, $i + 2, T_STRING, 'app', 'container')
                && $this->isChar($tokens, $i + 3, '[');
        }

        return $this->calledFunction($tokens, $i) === 'app'
            && $this->isChar($tokens, $i + 2, ')')
            && $this->isChar($tokens, $i + 3, '[');
    }

    /**
     * Whether token $i has the given id and, when texts are given, reads as one of
     * them, ignoring case.
     *
     * @param list<array{?int, string, int}> $tokens
     */
    private function isToken(array $tokens, int $i, int $id, string ...$texts): bool
    {
        return isset($tokens[$i])
            && $tokens[$i][0] === $id
            && ($texts === [] || $this->isOneOf($tokens[$i][1], $texts));
    }

    /**
     * Whether token $i is the given one-character token.
     *
     * @param list<array{?int, string, int}> $tokens
     */
    private function isChar(array $tokens, int $i, string $char): bool
    {
        return isset($tokens[$i]) && $tokens[$i][0] === null && $tokens[$i][1] === $char;
    }

    /**
     * @param list<string> $names
     */
    private function isOneOf(string $name, array $names): bool
    {
        return in_array(strtolower($name), array_map(strtolower(...), $names), true);
    }

    /**
     * A file's tokens as [id, text, line], without whitespace and comments, so that
     * neighbours in the list are neighbours in the code. A one-character token has a
     * null id.
     *
     * @return list<array{?int, string, int}>
     */
    private function tokens(string $path): array
    {
        if (isset($this->tokenCache[$path])) {
            return $this->tokenCache[$path];
        }

        $tokens = [];
        $line = 1;

        foreach (token_get_all((string) file_get_contents($this->root . '/' . $path)) as $token) {
            if (is_string($token)) {
                $tokens[] = [null, $token, $line];

                continue;
            }

            [$id, $text, $start] = $token;
            $line = $start + substr_count($text, "\n");

            if (! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = [$id, $text, $start];
            }
        }

        return $this->tokenCache[$path] = $tokens;
    }

    /**
     * The paths a `<source>` include or exclude names, normalised and sorted. A
     * directory filtered on anything but `.php` keeps its suffix in the result, since
     * it no longer measures the PHP in it.
     *
     * @return list<string>
     */
    private function sourcePaths(?SimpleXMLElement $parent): array
    {
        $paths = [];

        foreach ($parent->directory ?? [] as $directory) {
            $suffix = (string) $directory['suffix'];
            $paths[] = $this->normalise((string) $directory) . (in_array($suffix, ['', '.php'], true) ? '' : " (suffix {$suffix})");
        }

        foreach ($parent->file ?? [] as $file) {
            $paths[] = $this->normalise((string) $file);
        }

        sort($paths);

        return $paths;
    }

    /**
     * Every PHP file in the package, relative to its root and sorted: all but
     * dot-files and dot-directories, dependencies, generated output and the
     * excluded paths.
     *
     * @return list<string>
     */
    private function packageFiles(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file) => ! str_starts_with($file->getFilename(), '.')
                && ! ($file->isDir() && in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true)),
        ));

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($this->root) + 1);

            if ($file->getExtension() !== 'php' || array_filter($this->settings['excluded'], fn (string $excluded) => str_starts_with($path . '/', $excluded . '/')) !== []) {
                continue;
            }

            $files[] = $path;
        }

        if ($files === []) {
            $this->violation('.', 0, 'settings', "Found no PHP files to check under {$this->root}; is that the package root?");
        }

        sort($files);

        return $this->files = $files;
    }

    /**
     * src/: what the coverage gate measures and PHPStan analyses.
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        return array_values(array_filter($this->packageFiles(), fn (string $path) => str_starts_with($path, 'src/')));
    }

    /**
     * The code a consuming application runs: every directory but tests/ and
     * workbench/, and no file at the package root.
     *
     * @return list<string>
     */
    private function shippedFiles(): array
    {
        return array_values(array_filter(
            $this->packageFiles(),
            fn (string $path) => str_contains($path, '/') && ! str_starts_with($path, 'tests/') && ! str_starts_with($path, 'workbench/'),
        ));
    }

    /**
     * The first line of a package file that contains the needle, or 0.
     */
    private function lineOf(string $path, string $needle): int
    {
        foreach (is_file($this->root . '/' . $path) ? (file($this->root . '/' . $path) ?: []) : [] as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }

        return 0;
    }

    private function normalise(string $path): string
    {
        return (string) preg_replace('#^\./#', '', rtrim(trim($path), '/'));
    }

    /**
     * @param list<string> $paths
     */
    private function listing(array $paths): string
    {
        return $paths === [] ? 'nothing' : implode(', ', $paths);
    }

    /**
     * A problem with the settings, placed on the settings file's line that names the
     * subject, when one does.
     */
    private function settingsProblem(string $subject, string $message, string $rule = 'settings'): void
    {
        $this->violation(self::SETTINGS_FILE, $this->lineOf(self::SETTINGS_FILE, "'{$subject}'"), $rule, $message);
    }

    private function violation(string $path, int $line, string $rule, string $message): void
    {
        $this->violations[] = [$path, $line, $rule, $message];
    }

    /**
     * Print every violation, as a GitHub annotation when running in Actions, and a
     * closing line. 0 when there were none.
     */
    private function report(): int
    {
        if ($this->violations === []) {
            printf("The conventions hold: %d PHP files checked.\n", count($this->files ?? []));

            return 0;
        }

        $actions = getenv('GITHUB_ACTIONS') === 'true';

        foreach ($this->violations as [$path, $line, $rule, $message]) {
            $text = sprintf('%s %s — %s', $line > 0 ? "{$path}:{$line}" : $path, $rule, $message);

            if (! $actions) {
                echo $text, "\n";

                continue;
            }

            $properties = is_file($this->root . '/' . $path) ? 'file=' . $this->escape($path, true) . ($line > 0 ? ",line={$line}" : '') . ',' : '';
            echo '::error ', $properties, 'title=', $this->escape("conventions: {$rule}", true), '::', $this->escape($text, false), "\n";
        }

        $count = count($this->violations);
        $exceptions = array_values(array_intersect(self::ALLOWABLE, array_column($this->violations, 2)));

        printf("\n%d violation%s of the conventions, whose rules are in .github/scripts/conventions.php.", $count, $count === 1 ? '' : 's');

        if ($exceptions !== []) {
            $last = array_pop($exceptions);

            printf(
                " A genuine exception to %s is an exact, reasoned entry in %s: 'allowed' => ['<rule>' => ['<path>' => [<exact number of hits>, '<why>']]].",
                $exceptions === [] ? $last : implode(', ', $exceptions) . " or {$last}",
                self::SETTINGS_FILE,
            );
        }

        echo "\n";

        return 1;
    }

    /**
     * Escape a workflow command's value; a property also escapes `:` and `,`.
     */
    private function escape(string $value, bool $property): string
    {
        $value = strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);

        return $property ? strtr($value, [':' => '%3A', ',' => '%2C']) : $value;
    }
}

$root = realpath($argv[1] ?? dirname(__DIR__, 2));

if ($root === false || ! is_dir($root)) {
    fwrite(STDERR, 'No such directory: ' . ($argv[1] ?? dirname(__DIR__, 2)) . "\n");

    exit(2);
}

exit((new Conventions($root))->run());
