<!--
  filament-architecture — portable rules for AI coding assistants that read AGENTS.md
  (or similar instruction files). Copy this section into your project's AGENTS.md,
  .github/copilot-instructions.md, a .cursor/rules/*.mdc file, or your tool's equivalent.
  Full guide: https://github.com/sajibjahan/filament-architecture
-->

## Architecture: business logic stays out of Filament

This is a Laravel + Filament (v4/v5) app. Filament is a delivery mechanism, like a controller: it renders, collects input, calls **one use case**, and shows the result. Business rules live in use cases and the domain, which know nothing about Filament, HTTP, Laravel facades or (in strict mode) Eloquent.

Use case mode: pragmatic   <!-- or: strict -->
- **strict**: use cases take and return plain DTOs and persist through repository interfaces. No `App\Models` and no `Illuminate\*` in `app/Application` or `app/Domain`.
- **pragmatic**: use cases may use Eloquent models. Facades, global helpers, HTTP, Filament, mail, queues and `DB::transaction` still come in through injected interfaces.

### Layers
| Path | Contains |
|---|---|
| `app/Domain/<Context>/` | enums with rules, value objects, domain exceptions (extend `App\Domain\Shared\DomainException`), repository/gateway **interfaces**; entities in strict mode |
| `app/Application/<Context>/UseCases/` | one `final` class per user intention (`ApproveOrder`, `IssueRefund`) with one `execute()` method, plus `final readonly` Input/Result DTOs |
| `app/Infrastructure/<Context>/` | implementations: Eloquent repositories, mailers, payment/HTTP gateways, `Clock`, `TransactionManager`; bound in `app/Providers/DomainServiceProvider.php` (registered in `bootstrap/providers.php`) |
| `app/Filament/` | thin resources, pages and actions |

### Rules
1. **Every state change goes through a use case.** Reads for tables, filters, forms and badges may use Eloquent directly.
2. **Inside `app/Application` and `app/Domain`, never use** facades (`DB::`, `Mail::`, `Http::`, `Log::`, `Auth::`…), global helpers (`auth()`, `request()`, `now()`, `config()`, `event()`, `dispatch()`, `app()`…), `Carbon::now()`, `new DateTimeImmutable()`, `Filament\*`, `Livewire\*` or `Illuminate\Http\*`. Pass the actor id in the Input DTO, and inject `Clock`, `TransactionManager`, `EventPublisher` and business-named notifier/gateway interfaces.
3. **Filament closures only translate:** form `$data` + `auth()->id()` → Input DTO → `$useCase->execute()` → notification. Catch only `DomainException`, show `Notification::make()->danger()->title($e->getMessage())`, then `$action->halt()` (in actions) or `$this->halt()` (in Create/Edit pages). No business `if`s, calculations or writes in closures.
4. **`visible()`/`disabled()` ask the domain** (e.g. `$record->status->canBeApproved()`), and the use case re-checks the same rule.
5. **Default Eloquent writes must be routed through use cases:** `handleRecordCreation()` / `handleRecordUpdate()` on Create/Edit pages, `->using()` on Create/Edit/Delete actions, `->updateStateUsing()` on Toggle/Select/TextInput columns (which don't check policies, so guard with `disabled()`), and `saveRecord()` on importers (actor = `$this->import->user_id`; importers run in a queue).
6. **State transitions are actions, not `status` selects on edit forms.**
7. **Transitions run inside `TransactionManager::run()`** with a locked read or a conditional update, so two users can't both succeed. Notifications are sent after commit.
8. **Never call an external system (payments, couriers, HTTP APIs) inside a DB transaction or while holding a lock.** Use reserve (transaction) → call (no transaction, idempotency key) → confirm (transaction).
9. **Refactors preserve behaviour.** List anything that looks accidental as a question; don't silently fix it.
10. **Tests:** unit-test use cases with hand-written fakes (`InMemory…Repository`, `FixedClock`, `Spy…Notifier`). Filament tests stay thin and only prove the wiring.

### Enforcement
- Pest arch tests in `tests/Arch/LayersTest.php` forbid outer-layer and framework dependencies in `App\Domain` / `App\Application`, and forbid global helpers there (`expect([...])->not->toBeUsedIn([...])`).
- Optional heuristic scan: `php tools/filament-audit.php . --mode=pragmatic` (copy `scripts/audit.php` from the repo above). It exits with code 1 on violations.
