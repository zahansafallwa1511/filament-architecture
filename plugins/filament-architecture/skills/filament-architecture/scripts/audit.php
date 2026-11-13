<?php

declare(strict_types=1);

/**
 * Filament clean-architecture audit — a heuristic scanner.
 *
 * Finds *candidates* for:
 *   - business logic / side effects inside Filament (app/Filament)
 *   - infrastructure inside use cases (app/Application) and the domain (app/Domain)
 *
 * It reads code token-by-token with comments stripped and string contents masked, so
 * text inside strings and comments does not trigger findings. It cannot judge intent:
 * a human (or Claude) must read each finding.
 *
 * Usage:
 *   php audit.php [project-root] [--mode=strict|pragmatic] [--json]
 *                 [--filament=app/Filament] [--application=app/Application] [--domain=app/Domain]
 *
 * Exit codes: 0 = no errors, 1 = errors found, 2 = bad usage.
 */

const USAGE = <<<TXT
Usage: php audit.php [project-root] [--mode=strict|pragmatic] [--json]
                     [--filament=DIR] [--application=DIR] [--domain=DIR]

  project-root   Laravel project root (default: current directory)
  --mode         strict (default): no Eloquent/Illuminate in Application & Domain
                 pragmatic: Eloquent models allowed in Application; facades/helpers still forbidden
  --json         machine-readable output
  --filament     Filament layer dir, relative to root (default app/Filament); comma-separate several
  --application  Application layer dir(s) (default app/Application,app/Actions,app/UseCases — those that exist)
  --domain       Domain layer dir (default app/Domain)

TXT;

$root = getcwd();
$mode = 'strict';
$json = false;
// Defaults: every dir that exists is scanned. app/Actions and app/UseCases are common
// homes for use cases in Laravel projects, so they are treated as the Application layer.
$dirs = [
    'filament' => ['app/Filament'],
    'application' => ['app/Application', 'app/Actions', 'app/UseCases'],
    'domain' => ['app/Domain'],
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, USAGE);
        exit(0);
    } elseif (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, 7);
    } elseif ($arg === '--json') {
        $json = true;
    } elseif (preg_match('/^--(filament|application|domain)=(.+)$/', $arg, $m)) {
        $dirs[$m[1]] = array_map('trim', explode(',', $m[2]));
    } elseif (! str_starts_with($arg, '--')) {
        $root = $arg;
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n\n" . USAGE);
        exit(2);
    }
}

if (! in_array($mode, ['strict', 'pragmatic'], true)) {
    fwrite(STDERR, "--mode must be strict or pragmatic, got '{$mode}'\n");
    exit(2);
}

$rootReal = realpath($root);
if ($rootReal === false || ! is_dir($rootReal)) {
    fwrite(STDERR, "Project root not found: {$root}\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Rules
// Each rule: id, layers, modes (null = all), severity (error|warning|check),
// group (only the first matching rule per group is reported per line), pattern, message.
// ---------------------------------------------------------------------------

$helpers = 'auth|request|session|cache|config|env|now|today|event|dispatch|dispatch_sync|logger|info|app|resolve|redirect|route|url|response|view|broadcast|report|abort|abort_if|abort_unless';
$notAfter = '(?<![\w>:$])(?<!function )';
$facades = 'DB|Mail|Http|Queue|Bus|Event|Storage|Cache|Log|Auth|Gate|Session|Config|Notification|Process|Artisan|Redis|Cookie|Request|Route|URL|Date|Crypt|Hash|Validator|View|Response|Redirect|Schema|App|Lang|Password|RateLimiter';

$rules = [
    // ---------------- Filament layer ----------------
    [
        'id' => 'filament-model-write',
        'layers' => ['filament'],
        'severity' => 'error',
        'group' => 'write',
        'pattern' => '/->\s*(save|saveQuietly|update|updateQuietly|delete|deleteQuietly|forceDelete|restore|increment|decrement|attach|detach|sync|syncWithoutDetaching|syncWithPivotValues|associate|dissociate|push|touch|updateOrCreate|firstOrCreate|create|createQuietly|createMany|insert|upsert|updateExistingPivot)\s*\(/',
        'message' => 'Data write in Filament — move it into a use case and call that instead',
    ],
    [
        'id' => 'filament-static-write',
        'layers' => ['filament'],
        'severity' => 'error',
        'group' => 'write',
        'pattern' => '/\b(?!(Carbon\w*|DateTime\w*|DateInterval|DatePeriod|Str|Arr|Collection|LazyCollection|Number|Uri|Fluent|Stringable)::)[A-Z]\w*::\s*(create|forceCreate|insert|upsert|updateOrCreate|firstOrCreate|updateOrInsert|destroy)\s*\(/',
        'message' => 'Static model write in Filament — move it into a use case',
    ],
    [
        'id' => 'filament-side-effect',
        'layers' => ['filament'],
        'severity' => 'error',
        'group' => 'side-effect',
        'pattern' => '/(?<![\w\\\\>$])\\\\?(DB|Mail|Http|Queue|Bus|Event|Process|Artisan|Broadcast)::(?!class\b)/',
        'message' => 'Infrastructure / side effect in Filament (transaction, mail, HTTP, queue, event) — belongs behind a use case',
    ],
    [
        'id' => 'filament-laravel-notification',
        'layers' => ['filament'],
        'severity' => 'error',
        'group' => 'side-effect',
        'pattern' => '/(\bNotification::\s*(send|sendNow|route)\s*\(|->\s*(notify|notifyNow)\s*\()/',
        'message' => 'Notifying other users from Filament — a business side effect; move it to a use case (Filament\'s Notification::make() UI toasts are fine)',
    ],
    [
        'id' => 'filament-event-helper',
        'layers' => ['filament'],
        'severity' => 'error',
        'group' => 'side-effect',
        'pattern' => "/{$notAfter}(event|dispatch|dispatch_sync|broadcast)\s*\(/",
        'message' => 'Event/job dispatch from Filament — a business side effect; move it to a use case',
    ],
    [
        'id' => 'filament-lifecycle-hook',
        'layers' => ['filament'],
        'severity' => 'check',
        'group' => 'hook',
        'pattern' => '/function\s+(mutateFormDataBefore(Create|Save|Fill)|before(Create|Save|Fill|Validate|Delete)|after(Create|Save|Fill|Validate|Delete)|handleRecord(Creation|Update))\s*\(/',
        'message' => 'Lifecycle hook — make sure it only reshapes data or calls a use case',
    ],
    [
        'id' => 'filament-state-hook',
        'layers' => ['filament'],
        'severity' => 'check',
        'group' => 'hook',
        'pattern' => '/->\s*(beforeStateUpdated|afterStateUpdated|saveRelationshipsUsing)\s*\(/',
        'message' => 'State/relationship hook — runs around a default Eloquent write; business logic here stays in Filament',
    ],

    // ---------------- Application + Domain: both modes ----------------
    [
        'id' => 'facade-import',
        'layers' => ['application', 'domain'],
        'severity' => 'error',
        'group' => 'import',
        'pattern' => '/^\s*use\s+\\\\?Illuminate\\\\Support\\\\Facades\\\\/',
        'message' => 'Facade imported — inject a Domain interface instead',
    ],
    [
        'id' => 'framework-import',
        'layers' => ['application', 'domain'],
        'severity' => 'error',
        'group' => 'import',
        'pattern' => '/^\s*use\s+\\\\?(Filament|Livewire)\\\\|^\s*use\s+\\\\?Illuminate\\\\(Http|Contracts\\\\Auth|Auth|Routing|Session|Cookie|Mail|Queue|Bus|Notifications|Console)\\\\/',
        'message' => 'UI/framework class imported — use cases must not know about Filament, HTTP, auth, mail or queues',
    ],
    [
        'id' => 'outer-layer-import',
        'layers' => ['application', 'domain'],
        'severity' => 'error',
        'group' => 'import',
        'pattern' => '/^\s*use\s+\\\\?App\\\\(Filament|Http|Infrastructure|Livewire|Jobs|Mail|Notifications|Console)\\\\/',
        'message' => 'Depends on an outer layer — depend on a Domain interface and implement it in Infrastructure',
    ],
    [
        'id' => 'domain-imports-application',
        'layers' => ['domain'],
        'severity' => 'error',
        'group' => 'import',
        'pattern' => '/^\s*use\s+\\\\?App\\\\Application\\\\/',
        'message' => 'Domain depends on Application — dependencies must point inward (Application → Domain)',
    ],
    [
        'id' => 'facade-call',
        'layers' => ['application', 'domain'],
        'severity' => 'error',
        'group' => 'facade',
        'pattern' => "/(?<![\\w>$])\\\\?({$facades})::(?!class\\b)/",
        'message' => 'Facade call — hidden infrastructure; inject an interface (Clock, TransactionManager, EventPublisher, a notifier/gateway)',
    ],
    [
        'id' => 'global-helper',
        'layers' => ['application', 'domain'],
        'severity' => 'error',
        'group' => 'helper',
        'pattern' => "/{$notAfter}(?<!new )\\\\?({$helpers})\\s*\\(/",
        'message' => 'Laravel global helper — reads global state; pass the value in (actor id in the Input DTO) or inject an interface',
    ],
    [
        'id' => 'hidden-clock',
        'layers' => ['application', 'domain'],
        'severity' => 'error',
        'group' => 'clock',
        'pattern' => '/\b(Carbon|CarbonImmutable)::\s*(now|today|tomorrow|yesterday)\s*\(|new\s+\\\\?DateTime(Immutable)?\s*\(\s*([\'"](now|today)?[\'"])?\s*\)/i',
        'message' => 'Reads the system clock — inject Clock so tests can fix the time',
    ],
    [
        'id' => 'php-clock',
        'layers' => ['application', 'domain'],
        'severity' => 'warning',
        'group' => 'clock',
        'pattern' => "/{$notAfter}\\\\?(time|microtime|hrtime|date)\\s*\\(/",
        'message' => 'PHP time function — inject Clock instead',
    ],

    // ---------------- strict mode only ----------------
    [
        'id' => 'strict-illuminate-import',
        'layers' => ['application', 'domain'],
        'modes' => ['strict'],
        'severity' => 'error',
        'group' => 'import',
        'pattern' => '/^\s*use\s+\\\\?Illuminate\\\\/',
        'message' => 'Laravel class in a strict-mode use case/domain — use plain PHP types and Domain interfaces',
    ],
    [
        'id' => 'strict-model-import',
        'layers' => ['application', 'domain'],
        'modes' => ['strict'],
        'severity' => 'error',
        'group' => 'import',
        'pattern' => '/^\s*use\s+\\\\?App\\\\Models\\\\/',
        'message' => 'Eloquent model in a strict-mode use case/domain — use a Domain entity + repository interface',
    ],
    [
        'id' => 'strict-extends-model',
        'layers' => ['application', 'domain'],
        'modes' => ['strict'],
        'severity' => 'error',
        'group' => 'extends',
        'pattern' => '/\bextends\s+\\\\?(Illuminate\\\\Database\\\\Eloquent\\\\)?(Model|Pivot|Authenticatable)\b/',
        'message' => 'Eloquent model inside Domain/Application — models belong in app/Models (persistence)',
    ],

    // ---------------- pragmatic mode only ----------------
    [
        'id' => 'pragmatic-illuminate-import',
        'layers' => ['application'],
        'modes' => ['pragmatic'],
        'severity' => 'error',
        'group' => 'import',
        'pattern' => '/^\s*use\s+\\\\?Illuminate\\\\(?!Support\\\\(Collection|Str|Arr|Carbon)\b|Database\\\\Eloquent\\\\)/',
        'message' => 'Laravel infrastructure class in a use case — only Eloquent models/collections and Support\\{Collection,Str,Arr} are allowed in pragmatic mode',
    ],
    [
        'id' => 'pragmatic-domain-illuminate-import',
        'layers' => ['domain'],
        'modes' => ['pragmatic'],
        'severity' => 'error',
        'group' => 'import',
        'pattern' => '/^\s*use\s+\\\\?Illuminate\\\\(?!Support\\\\(Collection|Str|Arr)\b)/',
        'message' => 'Laravel class in the Domain — only Support\\{Collection,Str,Arr} are allowed',
    ],
];

// ---------------------------------------------------------------------------
// Scanning
// ---------------------------------------------------------------------------

/**
 * Returns the file's lines with comments removed and string contents masked,
 * preserving line numbers. 'now'/'today' string literals are kept so clock rules work.
 *
 * @return list<string>
 */
function codeLines(string $source): array
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            $out .= $token;
            continue;
        }
        [$id, $text] = $token;
        switch ($id) {
            case T_COMMENT:
            case T_DOC_COMMENT:
                $out .= preg_replace('/[^\n]/', ' ', $text);
                break;
            case T_CONSTANT_ENCAPSED_STRING:
                $quote = $text[0];
                $inner = substr($text, 1, -1);
                if (in_array(strtolower($inner), ['now', 'today', ''], true)) {
                    $out .= $text;
                } else {
                    $out .= $quote . preg_replace('/[^\n]/', '_', $inner) . $quote;
                }
                break;
            case T_ENCAPSED_AND_WHITESPACE:
            case T_INLINE_HTML:
                $out .= preg_replace('/[^\n]/', '_', $text);
                break;
            default:
                $out .= $text;
        }
    }

    return explode("\n", $out);
}

/** @return list<string> */
function phpFiles(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

$findings = [];
$skipped = [];
$scanned = 0;

foreach ($dirs as $layer => $layerDirs) {
    $existing = array_values(array_filter(
        $layerDirs,
        fn (string $rel) => is_dir($rootReal . DIRECTORY_SEPARATOR . ltrim($rel, '/\\'))
    ));
    if ($existing === []) {
        $skipped[] = implode(', ', $layerDirs) . " ({$layer}) not found";
        continue;
    }

    foreach ($existing as $rel) {
        $abs = $rootReal . DIRECTORY_SEPARATOR . ltrim($rel, '/\\');

        $layerRules = array_values(array_filter(
            $rules,
            fn (array $r) => in_array($layer, $r['layers'], true)
                && (! isset($r['modes']) || in_array($mode, $r['modes'], true))
        ));

        foreach (phpFiles($abs) as $path) {
            $scanned++;
            $source = (string) file_get_contents($path);
            $original = explode("\n", $source);
            $relPath = ltrim(substr($path, strlen($rootReal)), '/\\');

            foreach (codeLines($source) as $i => $line) {
                if (trim($line) === '') {
                    continue;
                }
                $seenGroups = [];
                foreach ($layerRules as $rule) {
                    if (isset($seenGroups[$rule['group']])) {
                        continue;
                    }
                    if (preg_match($rule['pattern'], $line)) {
                        $seenGroups[$rule['group']] = true;
                        $findings[] = [
                            'file' => $relPath,
                            'line' => $i + 1,
                            'layer' => $layer,
                            'severity' => $rule['severity'],
                            'rule' => $rule['id'],
                            'message' => $rule['message'],
                            'code' => trim($original[$i] ?? ''),
                        ];
                    }
                }
            }
        }
    }
}

$counts = ['error' => 0, 'warning' => 0, 'check' => 0];
foreach ($findings as $f) {
    $counts[$f['severity']]++;
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

if ($json) {
    echo json_encode([
        'mode' => $mode,
        'root' => $rootReal,
        'scanned_files' => $scanned,
        'skipped' => $skipped,
        'counts' => $counts,
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "Filament clean-architecture audit — mode: {$mode}\n";
    echo "Root: {$rootReal}\n";
    foreach ($skipped as $s) {
        echo "  (skipped: {$s})\n";
    }
    echo "\n";

    $byFile = [];
    foreach ($findings as $f) {
        $byFile[$f['file']][] = $f;
    }
    foreach ($byFile as $file => $items) {
        echo "{$file}\n";
        foreach ($items as $f) {
            printf("  %5d  %-7s  %-28s %s\n", $f['line'], strtoupper($f['severity']), $f['rule'], $f['message']);
            printf("         %s\n", mb_strimwidth($f['code'], 0, 140, '…'));
        }
        echo "\n";
    }

    printf(
        "Summary: %d error(s), %d warning(s), %d check(s) in %d file(s); scanned %d PHP file(s).\n",
        $counts['error'],
        $counts['warning'],
        $counts['check'],
        count($byFile),
        $scanned,
    );
    if ($findings !== []) {
        echo "These are heuristic candidates — read each one before acting. Reads (queries for tables/filters) in Filament are fine.\n";
    }
}

exit($counts['error'] > 0 ? 1 : 0);
