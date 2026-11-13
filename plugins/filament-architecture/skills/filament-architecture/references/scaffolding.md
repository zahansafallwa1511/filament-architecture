# Scaffolding the layers in a project

Use this when the user wants to set the architecture up (new project, or an existing one adopting it). Check what already exists first and only add what's missing — never overwrite an existing provider, base class or Pest config without showing the user.

## 1. Decide and record the mode

Ask (or detect, per SKILL.md Step 0) strict vs pragmatic, then add to the project's `CLAUDE.md` (create it if absent):

```markdown
## Architecture
Use case mode: strict
- For any Filament change that writes data or has side effects, use the filament-architecture skill.
- Business logic lives in app/Application/<Context>/UseCases (one class per user intention).
- app/Domain and app/Application contain no infrastructure: no facades, no global helpers, no Filament, no HTTP (strict: no Eloquent either).
- Filament (app/Filament) only maps input → Input DTO → use case → notification.
- Enforced by tests/Arch/LayersTest.php.
```

This section is how future sessions (and other developers' Claude) pick the right mode without asking, and the skill line makes Claude load this skill reliably for Filament work in this project — even for small one-file changes where it might otherwise just edit the closure directly.

## 2. Folders

```
app/
├── Domain/
│   └── Shared/
│       ├── Clock.php
│       ├── DomainException.php
│       ├── EventPublisher.php
│       └── TransactionManager.php
├── Application/
├── Infrastructure/
│   └── Shared/
│       ├── LaravelClock.php
│       ├── LaravelEventPublisher.php
│       └── LaravelTransactionManager.php
├── Filament/          (already exists)
├── Models/            (already exists)
└── Providers/
    └── DomainServiceProvider.php
tests/
├── Arch/LayersTest.php
├── Fakes/FixedClock.php
└── Unit/Application/
```

File contents for the Shared interfaces, implementations, `DomainException` and `DomainServiceProvider` are in `layer-rules.md` sections 3, 6 and 7. Register the provider in `bootstrap/providers.php`.

No composer autoload change is needed — everything is under `App\`.

## 3. Arch tests (Pest)

These make the boundaries fail CI instead of relying on review. Requires Pest 2.x+ (arch plugin is built in). If the project uses PHPUnit only, offer deptrac (`qossmic/deptrac`) instead, or ask before adding Pest.

**Strict mode** — `tests/Arch/LayersTest.php`:

```php
<?php

arch('domain is plain PHP')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate', 'Filament', 'Livewire', 'Carbon\Carbon',
        'App\Models', 'App\Application', 'App\Infrastructure', 'App\Filament', 'App\Http',
    ]);

arch('application depends only on the domain')
    ->expect('App\Application')
    ->not->toUse([
        'Illuminate', 'Filament', 'Livewire',
        'App\Models', 'App\Infrastructure', 'App\Filament', 'App\Http',
    ]);

arch('no hidden infrastructure through helpers')
    ->expect([
        'auth', 'request', 'session', 'cache', 'config', 'env', 'now', 'today',
        'event', 'dispatch', 'dispatch_sync', 'logger', 'info', 'app', 'resolve',
        'redirect', 'route', 'url', 'response', 'view', 'broadcast', 'report',
    ])
    ->not->toBeUsedIn(['App\Domain', 'App\Application']);

arch('use cases are final')
    ->expect('App\Application')
    ->classes()
    ->toBeFinal();
```

**Pragmatic mode** — same file, but Application may use models and Eloquent:

```php
<?php

arch('domain has no infrastructure')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate\Support\Facades', 'Illuminate\Http', 'Illuminate\Database\Query',
        'Filament', 'Livewire', 'App\Application', 'App\Infrastructure', 'App\Filament', 'App\Http',
    ]);

arch('application has no infrastructure')
    ->expect('App\Application')
    ->not->toUse([
        'Illuminate\Support\Facades', 'Illuminate\Http', 'Illuminate\Contracts\Auth',
        'Illuminate\Database\Query',
        'Filament', 'Livewire', 'App\Infrastructure', 'App\Filament', 'App\Http',
    ]);

arch('no hidden infrastructure through helpers')
    ->expect([
        'auth', 'request', 'session', 'cache', 'config', 'env', 'now', 'today',
        'event', 'dispatch', 'dispatch_sync', 'logger', 'info', 'app', 'resolve',
        'redirect', 'route', 'url', 'response', 'view', 'broadcast', 'report',
    ])
    ->not->toBeUsedIn(['App\Domain', 'App\Application']);
```

Run `./vendor/bin/pest tests/Arch` after creating it. If it fails on existing code, that's useful information — report the violations to the user rather than loosening the rules; offer to refactor them (see `refactoring.md`) or to add a temporary `->ignoring([...])` list with a TODO.

Pest arch tests see `use` imports and function calls; they cannot see business logic *in* Filament. That side is covered by `scripts/audit.php` and review.

## 4. Optional: CI audit

`scripts/audit.php` exits with code 1 when it finds violations, so it can run in CI alongside the arch tests. Since it ships with this skill rather than the project, offer to copy it to `tools/filament-audit.php` if the user wants it in CI.

## 5. First use case

Finish scaffolding by building one real use case end-to-end with the user (the most-used or riskiest action in their panel). A working example in their own domain teaches the pattern to the team better than empty folders.
