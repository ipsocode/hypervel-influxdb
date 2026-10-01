<?php

declare(strict_types=1);

// Imported from ipsocode/hypervel-packages github/scripts/conventions.php. Edit it there.

final class Conventions
{
    private const string SETTINGS_FILE = '.github/conventions.php';

    /** @var array<string, mixed> */
    private const array SETTINGS = [
        'slug' => null,
        'excluded' => [],
        'banned_namespaces' => [],
        'banned_functions' => [],
        'coverage_excludes' => [],
        'allowed' => [],
    ];

    /** @var list<string> */
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

    /** @var list<string> */
    private const array RAW_SQL_FACADE = [
        'raw', 'select', 'selectOne', 'selectFromWriteConnection', 'selectResultSets', 'scalar', 'cursor',
        'insert', 'update', 'delete', 'statement', 'affectingStatement', 'unprepared',
    ];

    /** @var list<string> */
    private const array RAW_SQL_METHODS = ['raw', 'statement', 'affectingStatement', 'unprepared'];

    /** @var list<string> */
    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'coverage', 'storage'];

    /** @var list<array{string, int, string, string}> */
    private array $violations = [];

    /** @var array{slug: string, excluded: list<string>, banned_namespaces: array<string, string>, banned_functions: array<string, string>, coverage_excludes: list<string>, allowed: array<string, array<string, array{int, string}>>} */
    private array $settings;

    /** @var null|list<string> */
    private ?array $files = null;

    /** @var array<string, list<array{?int, string, int}>> */
    private array $tokenCache = [];

    public function __construct(private readonly string $root)
    {
    }

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

    private function checkDependencies(): void
    {
        $this->conform(
            'dependency',
            is_file($this->root . '/composer.json') ? $this->textHits(['composer.json'], '/"((?:illuminate|laravel)\/[^"]+)"\s*:/i') : [],
            fn (string $package) => "`{$package}` is a Laravel package. Depend on the hypervel/* package that provides it.",
        );
    }

    private function checkContainerAccess(): void
    {
        $this->conform(
            'container-array-access',
            $this->tokenHits($this->packageFiles(), fn (array $tokens, int $i) => $this->isContainerArrayAccess($tokens, $i)),
            fn () => "Reads the container as an array. Use its methods: \$app->get('config').",
        );
    }

    private function checkCoverageIgnores(): void
    {
        $this->conform(
            'coverage-ignore',
            $this->textHits($this->sourceFiles(), '/@codeCoverageIgnore/'),
            fn () => '@codeCoverageIgnore hides code from the coverage gate. Cover it with a test, or delete it if nothing can reach it.',
        );
    }

    private function checkPhpstanIgnores(): void
    {
        $this->conform(
            'phpstan-ignore',
            $this->textHits($this->sourceFiles(), '/@phpstan-ignore-(?:next-)?line\b/'),
            fn () => 'Ignores every PHPStan error on the line. Name the one meant: @phpstan-ignore <identifier> (<reason>).',
        );
    }

    private function checkCoverageGate(): void
    {
        $rule = 'coverage-gate';

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

    private function checkRawSql(): void
    {
        $this->conform(
            'raw-sql',
            $this->tokenHits($this->shippedFiles(), fn (array $tokens, int $i) => $this->isRawSql($tokens, $i)),
            fn (string $method) => "`{$method}()` runs raw SQL. Build the query with the query builder, which binds its values.",
        );
    }

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

    private function checkNames(): void
    {
        $slug = $this->settings['slug'];
        $files = array_values(array_filter(
            $this->packageFiles(),
            fn (string $path) => str_starts_with($path, 'src/') || str_starts_with($path, 'config/'),
        ));

        $this->names('context-key', $files, [
            '/(?:CoroutineContext|Context)::(?:get|set|has|forget|getOrSet|destroy)\(\s*[\'"]([^\'"$]+)[\'"]/',
            '/const\s+(?:string\s+)?[A-Z][A-Z0-9_]*\s*=\s*[\'"]((?:__)?[a-z][a-z0-9_]*\.[^\'"$]*)[\'"]/',
            '/function\s+\w*ContextKey\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s+[\'"]([^\'"$]+)[\'"]/',
        ], '/^__' . $slug . '(\.[a-z0-9_*]+)*\.?$/', "__{$slug}.segment.segment");

        $this->names('store-key', $files, [
            '/->by\(\s*[\'"]([^\'"$]+)[\'"]/',
            '/->lock\(\s*[\'"]([^\'"$]+)[\'"]/',
            '/Cache::(?:get|put|add|forever|forget|remember|rememberForever|pull|has|increment|decrement)\(\s*[\'"]([^\'"$]+)[\'"]/',
        ], '/^' . $slug . '(:[a-z0-9_-]+)+:?$/', "{$slug}:segment:segment");

        $this->names('command-name', $files, [
            '/signature\s*=\s*[\'"]([a-z0-9:_-]+)/',
        ], '/^' . $slug . ':[a-z0-9]+(-[a-z0-9]+)*$/', "{$slug}:kebab-verb");

        $this->names('publish-tag', $files, [
            '/publishes(?:Migrations)?\(\s*\[[^\]]*\]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
        ], '/^' . $slug . '-(?:[a-z0-9]+|\{\$\w+\})(?:-(?:[a-z0-9]+|\{\$\w+\}))*$/', "{$slug}-group");

        $this->names('env-var', $files, [
            '/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/',
        ], '/^' . strtoupper($slug) . '_[A-Z0-9_]+$/', strtoupper($slug) . '_NAME');

        $this->names('config-name', $files, [
            '/mergeConfigFrom\(\s*[^,)\n]+,\s*[\'"]([^\'"]+)[\'"]/',
        ], '/^' . $slug . '$/', $slug);

        foreach ($files as $path) {
            if (preg_match('#^config/([^/]+)\.php$#', $path, $match) === 1 && $match[1] !== $slug) {
                $this->violation($path, 0, 'config-name', "config/{$match[1]}.php does not match the convention `config/{$slug}.php`. Rename it.");
            }
        }

        $this->names('facade-accessor', $files, [
            '/getFacadeAccessor\(\)\s*:\s*\??string\s*\{\s*return\s+([^;]+);/',
        ], '/^\'[a-z0-9]+(\.[a-z0-9]+)*\'$/', "'{$slug}'");
    }

    /**
     * @param array<string, list<array{int, string}>> $hits
     * @param Closure(string): string $message
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
     * @param list<string> $files
     * @param list<string> $patterns
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
     * @param list<string> $files
     * @param callable(string): bool $banned
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

    /** @param list<array{?int, string, int}> $tokens */
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

    /** @param list<array{?int, string, int}> $tokens */
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

    /** @param list<array{?int, string, int}> $tokens */
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

    /** @param list<array{?int, string, int}> $tokens */
    private function isToken(array $tokens, int $i, int $id, string ...$texts): bool
    {
        return isset($tokens[$i])
            && $tokens[$i][0] === $id
            && ($texts === [] || $this->isOneOf($tokens[$i][1], $texts));
    }

    /** @param list<array{?int, string, int}> $tokens */
    private function isChar(array $tokens, int $i, string $char): bool
    {
        return isset($tokens[$i]) && $tokens[$i][0] === null && $tokens[$i][1] === $char;
    }

    /** @param list<string> $names */
    private function isOneOf(string $name, array $names): bool
    {
        return in_array(strtolower($name), array_map(strtolower(...), $names), true);
    }

    /** @return list<array{?int, string, int}> */
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

    /** @return list<string> */
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

    /** @return list<string> */
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

    /** @return list<string> */
    private function sourceFiles(): array
    {
        return array_values(array_filter($this->packageFiles(), fn (string $path) => str_starts_with($path, 'src/')));
    }

    /** @return list<string> */
    private function shippedFiles(): array
    {
        return array_values(array_filter(
            $this->packageFiles(),
            fn (string $path) => str_contains($path, '/') && ! str_starts_with($path, 'tests/') && ! str_starts_with($path, 'workbench/'),
        ));
    }

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

    /** @param list<string> $paths */
    private function listing(array $paths): string
    {
        return $paths === [] ? 'nothing' : implode(', ', $paths);
    }

    private function settingsProblem(string $subject, string $message, string $rule = 'settings'): void
    {
        $this->violation(self::SETTINGS_FILE, $this->lineOf(self::SETTINGS_FILE, "'{$subject}'"), $rule, $message);
    }

    private function violation(string $path, int $line, string $rule, string $message): void
    {
        $this->violations[] = [$path, $line, $rule, $message];
    }

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
