# Layer rules

## Contents
1. Dependency rules per layer and mode
2. Why global helpers count as infrastructure
3. Standard interfaces (Clock, TransactionManager, EventPublisher, notifiers, gateways)
4. Strict mode: entity + repository example (+ concurrent transitions, external calls)
5. Pragmatic mode: the same use case
6. Domain exceptions
7. Service provider bindings

---

## 1. Dependency rules

Arrows point inward: Filament → Application → Domain. Infrastructure implements Domain interfaces and is only referenced from service providers (and Filament reads through Eloquent models).

### Domain (`app/Domain`)
| | strict | pragmatic |
|---|---|---|
| PHP, own Domain classes | yes | yes |
| `Illuminate\*` | **no** | only `Illuminate\Support\{Collection,Str,Arr}` |
| `App\Models\*` | **no** | allowed in interface signatures only |
| `Filament\*`, `Livewire\*`, `App\Filament`, `App\Http`, `App\Infrastructure`, `App\Application` | **no** | **no** |
| facades, global helpers | **no** | **no** |

### Application (`app/Application`)
| | strict | pragmatic |
|---|---|---|
| Domain | yes | yes |
| `App\Models\*`, `Illuminate\Database\Eloquent\{Model,Collection,ModelNotFoundException}` | **no** | yes |
| `Illuminate\Support\{Collection,Str,Arr}` | no (use arrays) | yes |
| Facades (`Illuminate\Support\Facades\*` or root aliases `\DB`, `\Mail`…) | **no** | **no** |
| Helpers with hidden I/O or global state: `auth() request() session() cache() config() env() now() today() event() dispatch() logger() info() app() resolve() redirect() route() url() response() view() broadcast() report()` | **no** | **no** |
| `Carbon::now()`, `new DateTimeImmutable()` without a fixed value, `time()`, `date()` | **no** — use `Clock` | **no** — use `Clock` |
| `Illuminate\Http\*`, `Filament\*`, `Livewire\*`, `App\Filament`, `App\Http`, `App\Infrastructure` | **no** | **no** |
| `$model->save()` / `->update()` / relationship writes | **no** (repositories) | yes |
| `DB::transaction` | **no** — `TransactionManager` | **no** — `TransactionManager` |

Carbon *values* (a `CarbonImmutable` you were given) are fine in pragmatic mode; in strict mode prefer `DateTimeImmutable`. The problem is never the type — it is reading the system clock from inside a use case.

### Infrastructure (`app/Infrastructure`)
Anything goes. Keep each class focused on implementing one Domain interface.

### Filament (`app/Filament`)
May use Application, Domain, Models, Laravel and Filament. Must not write to models or trigger side effects except through a use case. `auth()->id()`, `Notification::make()` (Filament UI notification) and `redirect()` are fine here — this is the boundary.

## 2. Why global helpers count as infrastructure

`now()`, `auth()`, `config()` and friends look harmless, but each one reaches into the Laravel container for global state. A use case that calls `now()` cannot be tested at a fixed date without booting Laravel; one that calls `auth()` silently breaks when run from a queue or CLI where nobody is logged in; one that calls `config()` hides an input. Passing these values in (actor id in the Input DTO, time from `Clock`, settings as constructor arguments) makes every input visible in the signature.

## 3. Standard interfaces

Put shared ones in `app/Domain/Shared/`, context-specific ones in `app/Domain/<Context>/`. Name them in business language — `OrderNotifier::orderApproved()`, not `Mailer::send()` — so the use case reads like the business process and Infrastructure decides *how* (mail, Slack, database notification).

```php
namespace App\Domain\Shared;

interface Clock
{
    public function now(): \DateTimeImmutable;
}

interface TransactionManager
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed;
}

interface EventPublisher
{
    public function publish(object $event): void;
}
```

Implementations:

```php
namespace App\Infrastructure\Shared;

use App\Domain\Shared\Clock;

final class LaravelClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return now()->toDateTimeImmutable(); // honours app timezone and Carbon::setTestNow()
    }
}
```

```php
namespace App\Infrastructure\Shared;

use App\Domain\Shared\TransactionManager;
use Illuminate\Database\ConnectionInterface;

final class LaravelTransactionManager implements TransactionManager
{
    public function __construct(private ConnectionInterface $db) {}

    public function run(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }
}
```

```php
namespace App\Infrastructure\Shared;

use App\Domain\Shared\EventPublisher;
use Illuminate\Contracts\Events\Dispatcher;

final class LaravelEventPublisher implements EventPublisher
{
    public function __construct(private Dispatcher $events) {}

    public function publish(object $event): void
    {
        $this->events->dispatch($event);
    }
}
```

Domain events are plain readonly classes in the Domain (`OrderApproved`). Laravel listeners in Infrastructure react to them; if a listener must only run after the transaction commits, make it a queued listener with `$afterCommit = true` (or implement `ShouldHandleEventsAfterCommit`), so the use case stays unaware.

External services get their own gateway interface: `PaymentGateway::refund(PaymentId $id, Money $amount): RefundReference`, implemented in `app/Infrastructure/Payments/StripePaymentGateway.php` with Laravel's `Http` client.

## 4. Strict mode: entity + repository

```php
namespace App\Domain\Order;

final class Order
{
    public function __construct(
        private int $id,
        private OrderStatus $status,
        private ?int $approvedBy = null,
        private ?\DateTimeImmutable $approvedAt = null,
        private ?string $approvalNote = null,
    ) {}

    public function approve(int $by, \DateTimeImmutable $at, ?string $note): void
    {
        if (! $this->status->canBeApproved()) {
            throw OrderCannotBeApproved::fromStatus($this->status);
        }

        $this->status = OrderStatus::Approved;
        $this->approvedBy = $by;
        $this->approvedAt = $at;
        $this->approvalNote = $note;
    }

    public function id(): int { return $this->id; }
    public function status(): OrderStatus { return $this->status; }
    public function approvedBy(): ?int { return $this->approvedBy; }
    public function approvedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function approvalNote(): ?string { return $this->approvalNote; }
}
```

```php
namespace App\Domain\Order;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function canBeApproved(): bool
    {
        return $this === self::Pending;
    }
}
```

The Eloquent model casts `status` to this same enum, so Filament's `visible()` can call `$record->status->canBeApproved()` and the UI and the use case share one rule.

```php
namespace App\Domain\Order;

interface OrderRepository
{
    /** @throws OrderNotFound */
    public function get(int $id): Order;

    public function save(Order $order): void;
}
```

```php
namespace App\Infrastructure\Order;

use App\Domain\Order\Order;
use App\Domain\Order\OrderNotFound;
use App\Domain\Order\OrderRepository;
use App\Models\Order as OrderModel;

final class EloquentOrderRepository implements OrderRepository
{
    public function get(int $id): Order
    {
        $row = OrderModel::query()->find($id) ?? throw OrderNotFound::withId($id);

        return new Order(
            id: $row->id,
            status: $row->status,
            approvedBy: $row->approved_by,
            approvedAt: $row->approved_at?->toDateTimeImmutable(),
            approvalNote: $row->approval_note,
        );
    }

    public function save(Order $order): void
    {
        OrderModel::query()->whereKey($order->id())->update([
            'status' => $order->status(),
            'approved_by' => $order->approvedBy(),
            'approved_at' => $order->approvedAt(),
            'approval_note' => $order->approvalNote(),
        ]);
    }
}
```

**Concurrent transitions.** Two admins clicking Approve at the same moment must not both succeed (double emails, double stock moves). The use case runs load → check → save inside `TransactionManager::run()`, and the repository makes the read lock or the write conditional — both are infrastructure details:

- pessimistic: `get()` uses `OrderModel::query()->lockForUpdate()->find($id)` (only meaningful inside the transaction), or
- optimistic: `save()` does `->whereKey($id)->where('status', $expectedPreviousStatus)->update([...])` and throws a domain exception (`OrderWasChangedConcurrently`) when 0 rows changed.

Send notifications after the transaction commits (call the notifier after `run()` returns, or use after-commit queued mail/listeners).

**External calls (payments, couriers, any HTTP API).** A database transaction cannot roll back Stripe. If you call the gateway inside `run()` and the commit then fails, money moved with no record; holding a row lock across a slow HTTP call also blocks other admins. Use three short steps instead:

```php
public function execute(RefundPaymentInput $input): RefundResult
{
    // 1. Reserve: validate under lock and record intent, then commit.
    $refundId = $this->transactions->run(function () use ($input): int {
        $payment = $this->payments->getForUpdate($input->paymentId);
        $payment->assertCanRefund($input->amount, $this->clock->now()); // throws domain exceptions
        return $this->refunds->addPending($payment->id(), $input->amount, $input->actorId);
    });

    // 2. Call the outside world, outside any transaction, with an idempotency key.
    try {
        $reference = $this->gateway->refund($input->paymentId, $input->amount, idempotencyKey: "refund-{$refundId}");
    } catch (PaymentGatewayRejected $e) {
        $this->refunds->markFailed($refundId, $e->getMessage()); // releases the reserved amount
        throw RefundFailed::because($e->getMessage());           // DomainException → shown to the admin
    }

    // 3. Confirm.
    $this->transactions->run(fn () => $this->refunds->markSucceeded($refundId, $reference));

    return new RefundResult($refundId, $reference);
}
```

Pending reservations count against the refundable amount, so two admins can't over-refund concurrently. Unknown outcomes (timeouts) stay `pending` for reconciliation (webhook or scheduled check) rather than being guessed. If the gateway client doesn't support idempotency keys, say so and suggest adding them. The same shape applies to anything irreversible outside your database.

Creating records in strict mode: either generate ids up front (UUID/ULID via an `IdGenerator` interface — simplest), or give the repository an `add(...)` method that returns the new id. The Filament Create page then re-reads the model by that id (see `filament-patterns.md`).

Strict mode costs a mapping layer per aggregate. It pays off when rules are rich or the code is shared by several entry points; say so honestly if the user's domain is mostly CRUD.

## 5. Pragmatic mode: the same use case

```php
namespace App\Application\Order\UseCases;

use App\Domain\Order\OrderCannotBeApproved;
use App\Domain\Order\OrderNotifier;
use App\Domain\Order\OrderStatus;
use App\Domain\Shared\Clock;
use App\Models\Order;

final class ApproveOrder
{
    public function __construct(
        private OrderNotifier $notifier,
        private Clock $clock,
    ) {}

    public function execute(Order $order, int $approvedBy, ?string $note = null): Order
    {
        if (! $order->status->canBeApproved()) {
            throw OrderCannotBeApproved::fromStatus($order->status);
        }

        $order->status = OrderStatus::Approved;
        $order->approved_by = $approvedBy;
        $order->approved_at = $this->clock->now();
        $order->approval_note = $note;
        $order->save();

        $this->notifier->orderApproved($order->id);

        return $order;
    }
}
```

Pragmatic mode may also take an Input DTO instead of loose arguments — prefer a DTO once there are more than ~3 inputs.

## 6. Domain exceptions

```php
namespace App\Domain\Shared;

abstract class DomainException extends \DomainException {}
```

```php
namespace App\Domain\Order;

use App\Domain\Shared\DomainException;

final class OrderCannotBeApproved extends DomainException
{
    public static function fromStatus(OrderStatus $status): self
    {
        return new self("Only pending orders can be approved (this one is {$status->value}).");
    }
}
```

Messages are written for the end user because Filament shows them in a notification. Anything that is *not* a `DomainException` (database down, bug) should propagate and be handled by Laravel's normal error reporting — never catch `\Throwable` in Filament to show a friendly message, it hides bugs.

## 7. Bindings

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public array $bindings = [
        \App\Domain\Shared\Clock::class => \App\Infrastructure\Shared\LaravelClock::class,
        \App\Domain\Shared\TransactionManager::class => \App\Infrastructure\Shared\LaravelTransactionManager::class,
        \App\Domain\Shared\EventPublisher::class => \App\Infrastructure\Shared\LaravelEventPublisher::class,
        \App\Domain\Order\OrderRepository::class => \App\Infrastructure\Order\EloquentOrderRepository::class,
        \App\Domain\Order\OrderNotifier::class => \App\Infrastructure\Order\MailOrderNotifier::class,
    ];
}
```

Register it in `bootstrap/providers.php` (Laravel 11+) or `config/app.php` (older). Use cases themselves need no binding — the container autowires them.
