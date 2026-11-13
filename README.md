# Filament Architecture: a Claude skill

A Claude Code plugin (and claude.ai skill) that teaches Claude to keep **business logic out of Filament** in Laravel apps. It also works with other AI assistants: see [Other AI assistants](#other-ai-assistants-codex-cursor-copilot-). Filament resources, pages and actions stay thin. Rules, calculations and side effects go into **use case classes** that contain no infrastructure: no facades, no global helpers, no HTTP and no Filament. In strict mode they contain no Eloquent either.

Supports **Filament v4 and v5**.

## What it does

| You say | Claude does |
|---|---|
| "Add an Approve action to OrderResource" | Creates an `ApproveOrder` use case + input DTO, domain exception, interfaces for mail/clock, an Infrastructure implementation and binding, a thin Filament action, and a unit test with fakes |
| "This EditInvoice page is a mess, clean it up" | Inventories the logic, extracts one use case per user intention, replaces free `status` selects with explicit actions, and keeps behaviour identical. It raises any quirks it finds as questions instead of silently changing them |
| "Review my Filament code" | Runs a bundled scanner, then reads the code and writes a report: business logic in Filament, infrastructure in use cases, hidden logic in observers, duplicated rules, and a prioritised plan |
| "Set up clean architecture in this project" | Creates the `app/Domain`, `app/Application` and `app/Infrastructure` layers, shared interfaces (Clock, TransactionManager, EventPublisher), a service provider, and **Pest arch tests** that fail CI when a layer leaks |

### Two strictness levels

- **strict**: use cases take and return plain DTOs and persist through repository interfaces. No `App\Models` and no `Illuminate\*` in Application or Domain.
- **pragmatic**: use cases may use Eloquent models, but facades, helpers (`now()`, `auth()`, `config()`…), HTTP, Filament, mail, queues and `DB::transaction` must still come through injected interfaces.

Claude detects the mode from your `CLAUDE.md` (`Use case mode: strict`) or from your existing code, and asks once if it can't tell.

### Layout

```
app/
├── Domain/<Context>/         enums with rules, value objects, exceptions, interfaces (+ entities in strict mode)
├── Application/<Context>/
│   └── UseCases/             one class per user intention + Input/Result DTOs
├── Infrastructure/<Context>/ Eloquent repositories, mailers, HTTP clients, clock, transactions
└── Filament/                 thin: map input → use case → notification
```

## Install

### Claude Code

```
/plugin marketplace add zahansafallwa1511/filament-architecture
/plugin install filament-architecture@filament-architecture
```

Then just work normally ("add a refund action to the payments table"). The skill triggers on its own, or you can call it with `/filament-architecture`.

### claude.ai (web / desktop)

1. Download `plugins/filament-architecture/skills/filament-architecture` as a zip (or grab it from Releases).
2. Go to **Settings → Capabilities → Skills → Upload skill**.

### Other AI assistants (Codex, Cursor, Copilot, …)

The skill is written for Claude, but the architecture isn't Claude-specific. Pick whichever option your tool supports:

1. **Agent Skills support.** The skill folder uses the open Agent Skills format (`SKILL.md` plus `references/` and `scripts/`). If your assistant supports skills, copy `plugins/filament-architecture/skills/filament-architecture` into its skills directory. The location varies by tool, so check its docs.
2. **`AGENTS.md` / instruction files.** Copy the section in [`agents/AGENTS.md`](agents/AGENTS.md) into your project's `AGENTS.md`, or into your tool's instruction file (`.github/copilot-instructions.md`, a `.cursor/rules/*.mdc` rule, and so on). It's a condensed, self-contained version of the rules. Set the `Use case mode:` line to `strict` or `pragmatic`.
3. **Enforcement, whoever writes the code.** Add the Pest arch tests (see `references/scaffolding.md`) and, optionally, the audit script to CI. They fail the build when business logic or infrastructure ends up in the wrong layer, whether a person, Claude or any other assistant wrote it.

Option 2 loses the "load detailed guides only when needed" behaviour that skills have. The rules still apply, but the assistant sees fewer worked examples, so options 1 and 3 give the most consistent results.

## The audit script

The skill bundles a dependency-free PHP scanner that you can also run yourself or in CI:

```bash
php plugins/filament-architecture/skills/filament-architecture/scripts/audit.php /path/to/laravel-app --mode=strict
# --mode=pragmatic, --json, --filament=DIR, --application=DIR, --domain=DIR
```

It exits with code 1 when it finds errors. It is a heuristic: it ignores comments and string contents and treats Filament reads (table queries, filters) as fine, but every finding still deserves a human look.

## Tests for the skill

`plugins/filament-architecture/evals/` contains four eval cases with realistic fixture projects: a strict-mode feature, a pragmatic refactor, a review, and a feature in a project with no architecture hints at all. In the latest run, Claude **with** the skill passed 32/32 checks and Claude **without** it passed 23/32. The biggest gaps were infrastructure left inside use cases during refactors, and logic coupled straight to the Stripe client when the project gave no hints. Run them with:

```bash
claude plugin eval plugins/filament-architecture --scaffold --allow-tools Write,Edit,Bash
```

## Contributing

Issues and PRs are welcome, especially fixes for Filament API changes, new seams (Filament extension points that write data), and audit-script false positives with a minimal reproducer.

## License

MIT
