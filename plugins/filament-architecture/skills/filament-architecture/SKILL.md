---
name: filament-architecture
description: Keep business logic out of Filament (v4/v5) and in framework-free UseCase classes in Laravel apps. Use this whenever the user adds, changes, refactors or reviews anything in a Filament resource, page, table/header/bulk action, toggle/select column, relation manager, importer or Create/Edit hook that writes data or has side effects — status changes, money or stock maths, limits, emails, notifications, external APIs — even for one small action, and even if they never mention use cases or architecture. Examples - "add a Mark as paid action to InvoicesTable", "this EditInvoice page is huge and untestable", "where should this logic go", "review my Filament code", "set up clean architecture / DDD layers with Pest arch tests". Not for display-only Filament work (theming, column formatting, read-only tables, a plain generated CRUD resource) or for non-Filament code.
---

# Filament + UseCases

Filament is a delivery mechanism, exactly like a controller. Its job is to render, collect input, call **one use case**, and show the result. The business rules — what is allowed, what changes, what happens next — live in **Application use cases** and the **Domain**, which know nothing about Laravel, Filament, HTTP or the database. That lets the rules be unit-tested in milliseconds and reused unchanged from an API endpoint, an artisan command or a queued job.

A quick test for "is this business logic?": *if the same operation were triggered from an API endpoint or a CLI command, would this code have to be copied there?* If yes, it belongs in a use case, not in Filament.

## Layers (layered layout)

| Layer | Path | Contains | May depend on |
|---|---|---|---|
| Domain | `app/Domain/<Context>/` | enums with rules, value objects, domain exceptions, repository/gateway **interfaces**; entities in strict mode | PHP only |
| Application | `app/Application/<Context>/UseCases/` | one class per user intention + its Input/Result DTOs | Domain (+ models in pragmatic mode) |
| Infrastructure | `app/Infrastructure/<Context>/` | Eloquent repositories, mailers, HTTP clients, clock, transactions — implementations of Domain interfaces | anything |
| Filament | `app/Filament/` | resources, pages, actions, schemas, tables | Application, Domain, Models |
| Models | `app/Models/` | Eloquent models (persistence + read model) | Laravel |

**Reads vs writes.** Filament tables, filters, form prefill and infolists read Eloquent directly — that is fine and expected; do not build repositories for table queries, it fights Filament for no gain. **Every state change goes through a use case.**

## Step 0 — detect context (always, before writing code)

1. **Filament version** — read `filament/filament` in `composer.lock`. v4 and v5 share the same API; proceed. If it is v3, tell the user this skill targets v4/v5: the architecture applies unchanged but the Filament syntax differs (`Filament\Forms\Form`, `Filament\Tables\Actions\*`), so adapt carefully. When unsure of any Filament method name or signature, grep `vendor/filament` instead of guessing.
2. **Mode — strict or pragmatic.** The two modes differ only in whether use cases may touch Eloquent models:
   - **strict**: use cases take/return plain DTOs, persist through repository interfaces; no `App\Models`, no `Illuminate\*` in Application or Domain.
   - **pragmatic**: use cases may receive, change and save Eloquent models; still no facades, helpers, HTTP, Filament, or direct mail/queue/DB-transaction calls.

   Find the mode in this order: (a) a line `Use case mode: strict` / `pragmatic` in the project's `CLAUDE.md`, `AGENTS.md` or `docs/architecture.md`; (b) existing code — `app/Application` importing `App\Models` means pragmatic, repository interfaces in `app/Domain` with plain-PHP entities means strict; (c) ask the user once, with the one-line tradeoff above. If you cannot ask, choose pragmatic for an existing Eloquent-heavy app and strict for a greenfield one, and say which you chose. Offer to record the choice in `CLAUDE.md` (the "Architecture" block in `references/scaffolding.md`, which also tells Claude to use this skill for Filament changes) so nobody has to decide again.
3. **Existing conventions** — if the project already has use cases or actions (e.g. `app/Actions`, spatie/laravel-data DTOs, `__invoke` vs `execute`), follow its naming and style rather than introducing a parallel one, while still keeping infrastructure out. In strict mode, DTOs crossing into the Application layer should be plain readonly classes, not framework-backed data objects.

The full allowed/forbidden list per layer and mode, and the standard interfaces (Clock, TransactionManager, EventPublisher, notifiers), are in `references/layer-rules.md`. Read it the first time you write a use case in a session.

## What stays in Filament vs. what moves

**Stays in Filament (UI concerns):**
- layout, labels, columns, filters, sorting, search, icons, colours
- input-format validation (`required`, `email`, `maxLength`, `numeric`) — this is UX; the use case still guards the invariants that matter
- `visible()` / `disabled()` / `hidden()` — but *ask the domain* (`fn (Order $record) => $record->status->canBeApproved()`) rather than re-implementing the rule inline, so the UI and the use case can never disagree
- authorization at the boundary (`->authorize('approve')`, policies)
- mapping the form `$data` array and `auth()->id()` into an Input DTO
- mapping domain exceptions to notifications; success notifications; redirects

**Moves to a use case / the domain:**
- status transitions and their preconditions
- calculations — totals, tax, discounts, prices, stock
- anything that writes: create/update/delete, attach/sync, increment
- side effects: emails, notifications to *other* users, events, jobs, external API calls
- multi-step work that needs a transaction
- values with business meaning — invoice numbers, due dates, default statuses
- cross-field rules ("end after start") — may also appear in the form for UX, but the use case is the source of truth

## Shape of a use case

```php
namespace App\Application\Order\UseCases;

final class ApproveOrder
{
    public function __construct(
        private OrderRepository $orders,          // Domain interface
        private OrderNotifier $notifier,          // Domain interface
        private Clock $clock,                     // Domain\Shared interface
        private TransactionManager $transactions, // Domain\Shared interface
    ) {}

    public function execute(ApproveOrderInput $input): void
    {
        $this->transactions->run(function () use ($input): void {
            $order = $this->orders->get($input->orderId); // repository locks the row (see layer-rules.md)
            $order->approve($input->approvedBy, $this->clock->now(), $input->note); // throws OrderCannotBeApproved
            $this->orders->save($order);
        });

        $this->notifier->orderApproved($input->orderId); // after commit
    }
}

final readonly class ApproveOrderInput
{
    public function __construct(
        public int $orderId,
        public int $approvedBy,
        public ?string $note = null,
    ) {}
}
```

Conventions:
- `final` class named verb + noun for **one user intention** (`ApproveOrder`, `IssueRefund`, not `OrderService`). One public method, `execute()` (or the project's existing convention).
- Input is a `final readonly` DTO of scalars, enums and value objects. Who is acting (`approvedBy`) is passed in — the use case never calls `auth()`.
- Rule violations throw a domain exception extending `App\Domain\Shared\DomainException` with a message that is safe to show a user. Filament catches that one base type.
- Return `void`, an id, or a small Result DTO. In pragmatic mode returning the model is fine.
- Time, transactions, events, mail and external calls come in through constructor-injected interfaces. That is what keeps the use case testable without Laravel.
- Never call an external system (payment provider, courier, any HTTP API) inside a database transaction or while holding a row lock: if the database fails after the call succeeds, the outside world and your records disagree. Reserve → call → confirm instead — see "External calls" in `references/layer-rules.md`.

In pragmatic mode the same use case receives `Order $order` (an Eloquent model), checks the rule (ideally via a domain enum method), sets attributes and calls `$order->save()`; collaborators are still injected interfaces. See `references/layer-rules.md` for both versions side by side.

## Shape of thin Filament code

```php
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

Action::make('approve')
    ->requiresConfirmation()
    ->authorize('approve')
    ->visible(fn (Order $record): bool => $record->status->canBeApproved())
    ->schema([Textarea::make('note')->maxLength(500)])
    ->action(function (Order $record, array $data, Action $action, ApproveOrder $approveOrder): void {
        try {
            $approveOrder->execute(new ApproveOrderInput(
                orderId: $record->getKey(),
                approvedBy: auth()->id(),
                note: $data['note'] ?? null,
            ));
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt(); // keep the modal open so the user can correct and retry
        }

        Notification::make()->success()->title('Order approved')->send();
    });
```

The closure only translates: UI input → Input DTO → use case → notification. Filament resolves `ApproveOrder` from the container through closure injection, so dependencies stay wired in the service provider. If an action closure holds anything beyond that mapping and the try/catch — an `if` about business state, a calculation, a write — something has leaked.

`->authorize('approve')` checks the model's policy. Add it when an `OrderPolicy` (or a matching gate) exists; if none exists, don't add it blindly (it would hide the action for everyone) — mention to the user that the action is unguarded and offer a policy.

Create/edit pages, bulk actions, relation managers, inline-editable columns, importers and wizards each have their own seam — see `references/filament-patterns.md` before touching any of them. The two most common traps: Create/Edit pages and inline columns (`ToggleColumn`, `SelectColumn`, `TextInputColumn`) write to Eloquent **by default**, so business fields must be routed through a use case explicitly; and a `status` select on an edit form usually hides a state transition that deserves its own action and use case.

## Workflows

### Build a new feature
1. Step 0.
2. Name the user intentions in the request — one intention, one use case.
3. Add the Domain pieces it needs: exception, enum rule, interface, entity method (strict).
4. Write the use case and its DTOs.
5. Implement any new interface in Infrastructure and bind it in a service provider (create `app/Providers/DomainServiceProvider.php` if the project has none, and register it in `bootstrap/providers.php` — an unregistered provider means a runtime "target is not instantiable" error).
6. Wire the thin Filament code.
7. Write a Pest unit test for the use case using in-memory fakes, no database, no Laravel boot (strict); in pragmatic mode a database-backed test is fine. See `references/testing.md`.
8. Run the audit script on the result (below) and fix anything it flags.

### Refactor existing Filament code
Read `references/refactoring.md`. In short: inventory every closure and hook that decides or writes, group them into user intentions, extract one intention at a time while keeping behaviour identical (including notifications, redirects and hidden side effects from observers), and tell the user about any behaviour you find that looks accidental instead of silently "fixing" it.

A refactor moves code; it doesn't redesign screens. If the UI itself embodies the problem — typically a free `status` select on an edit form — and the user asked for behaviour to stay the same, keep the UI, route it through use cases, and recommend the UI change (explicit Approve/Reject actions) as a clear next step. If the user asked for a redesign or gave you free rein, make the change.

### Review / audit
Run the bundled heuristic scanner first, then read the flagged code and judge it yourself — the script finds candidates, not verdicts (an `Order::query()` inside a table filter is a read and fine; `->update()` inside an action is not):

```bash
php <this-skill-dir>/scripts/audit.php <project-root> --mode=strict   # or --mode=pragmatic
```

Also look for what a regex cannot see: rules duplicated between `visible()` and the use case, business logic hidden in model observers/`booted()` hooks, status fields on edit forms, use cases that are thin wrappers while the real rules stay in Filament.

Report findings in this structure:

```
## Filament architecture review (<mode> mode)

### Business logic in Filament
- `path/File.php:42` — what it does → why it matters → fix (e.g. extract `ApproveOrder` use case)

### Hidden business logic outside Filament
- observers, model `booted()` hooks, mutators ...

### Infrastructure in Application / Domain
- ...

### Smells
- ...

### Suggested order of work
1. ... (highest risk / most duplicated first)

### Questions for the team
- behaviour that looks accidental and needs a product decision
```

Only report real problems. Reads in Filament (table queries, filters, navigation badges), display formatting and Filament's own `Notification::make()` toasts are fine — listing them as violations buries the findings that matter.

### Scaffold the architecture
Read `references/scaffolding.md`: folders, shared interfaces (Clock, TransactionManager, EventPublisher), base `DomainException`, service provider bindings, Pest arch tests that fail the build when a layer leaks, and the `Use case mode:` line for `CLAUDE.md`.

## Reference files
- `references/layer-rules.md` — exact allowed/forbidden dependencies per layer and mode; standard interfaces; strict vs pragmatic examples
- `references/filament-patterns.md` — the seam for every Filament extension point (v4/v5)
- `references/refactoring.md` — step-by-step extraction with a before/after example
- `references/testing.md` — use case tests with fakes, thin Filament tests
- `references/scaffolding.md` — setting the layers up in a project, arch tests
- `scripts/audit.php` — heuristic scanner; `--json` for machine output, exit code 1 when violations are found
