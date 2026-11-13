# filament-architecture

Teaches Claude to keep business logic out of Filament (v4/v5) in Laravel apps. Resources, pages and actions stay thin. Rules, calculations and side effects go into framework-free use case classes, which are unit-testable and reusable from APIs, commands and jobs.

It covers four jobs: **building** new Filament features the right way, **refactoring** existing logic out of resources, pages, actions and hooks without changing behaviour, **reviewing** a codebase for misplaced logic, and **scaffolding** Domain/Application/Infrastructure layers with Pest arch tests.

It supports two strictness levels: **strict** (no Eloquent in use cases) and **pragmatic** (Eloquent allowed; facades, helpers, HTTP and Filament still forbidden). It ships a dependency-free PHP audit script you can also run in CI.

See the [repository README](../../README.md) for installation and details.

## Install

```
/plugin marketplace add sajibjahan/filament-architecture
/plugin install filament-architecture@filament-architecture
```
