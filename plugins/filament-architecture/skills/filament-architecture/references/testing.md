# Testing

The payoff of keeping use cases free of infrastructure is that the important tests — the business rules — are fast, plain unit tests.

## Use case tests (strict mode): no Laravel, no database

```php
// tests/Unit/Application/Order/ApproveOrderTest.php
use App\Application\Order\UseCases\ApproveOrder;
use App\Application\Order\UseCases\ApproveOrderInput;
use App\Domain\Order\Order;
use App\Domain\Order\OrderCannotBeApproved;
use App\Domain\Order\OrderStatus;
use Tests\Fakes\FixedClock;
use Tests\Fakes\ImmediateTransactionManager;
use Tests\Fakes\InMemoryOrderRepository;
use Tests\Fakes\SpyOrderNotifier;

beforeEach(function () {
    $this->orders = new InMemoryOrderRepository();
    $this->notifier = new SpyOrderNotifier();
    $this->useCase = new ApproveOrder(
        $this->orders,
        $this->notifier,
        new FixedClock('2026-03-01 10:00'),
        new ImmediateTransactionManager(),
    );
});

it('approves a pending order and notifies', function () {
    $this->orders->add(new Order(id: 1, status: OrderStatus::Pending));

    $this->useCase->execute(new ApproveOrderInput(orderId: 1, approvedBy: 7, note: 'ok'));

    $order = $this->orders->get(1);
    expect($order->status())->toBe(OrderStatus::Approved)
        ->and($order->approvedBy())->toBe(7)
        ->and($order->approvedAt()->format('Y-m-d H:i'))->toBe('2026-03-01 10:00')
        ->and($this->notifier->approved)->toBe([1]);
});

it('refuses to approve a cancelled order', function () {
    $this->orders->add(new Order(id: 1, status: OrderStatus::Cancelled));

    $this->useCase->execute(new ApproveOrderInput(orderId: 1, approvedBy: 7));
})->throws(OrderCannotBeApproved::class);
```

Put fakes in `tests/Fakes/`. They are tiny:

```php
final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<int, Order> */
    private array $orders = [];

    public function add(Order $order): void { $this->orders[$order->id()] = $order; }

    public function get(int $id): Order
    {
        return $this->orders[$id] ?? throw OrderNotFound::withId($id);
    }

    public function save(Order $order): void { $this->orders[$order->id()] = $order; }
}

final class FixedClock implements Clock
{
    public function __construct(private string $now) {}
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable($this->now); }
}

final class ImmediateTransactionManager implements TransactionManager
{
    public function run(callable $callback): mixed { return $callback(); }
}
```

Fakes live under `tests/`, so they need the `Tests\` namespace autoloaded — standard Laravel `composer.json` has `"Tests\\": "tests/"` in `autoload-dev`; check it exists before relying on it.

Prefer hand-written fakes over mocks: they read clearly, and use cases are `final`, which mocking libraries dislike anyway.

If the project's Pest config applies `Tests\TestCase` to `tests/Unit`, these tests still work; they just don't need it.

## Use case tests (pragmatic mode)

Models are involved, so use the database (`RefreshDatabase` or the project's equivalent) and factories, but still construct the use case with fakes for every injected interface:

```php
it('sends a draft invoice', function () {
    $invoice = Invoice::factory()->draft()->create();
    $notifier = new SpyInvoiceNotifier();

    (new SendInvoice($notifier, new FixedClock('2026-03-01 10:00')))->execute($invoice);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($notifier->sent)->toBe([$invoice->id]);
});
```

## Filament tests: thin

Filament code now only translates, so one feature test per action/page is enough — it proves the wiring, not the rules:

```php
use Filament\Actions\Testing\TestAction;
use function Pest\Livewire\livewire;

it('approves from the orders table', function () {
    $this->actingAs(User::factory()->admin()->create());
    $order = Order::factory()->pending()->create();

    livewire(ListOrders::class)
        ->callAction(TestAction::make('approve')->table($order), data: ['note' => 'ok'])
        ->assertNotified('Order approved');

    expect($order->fresh()->status)->toBe(OrderStatus::Approved);
});
```

In v4/v5, table row actions are called with `callAction(TestAction::make('name')->table($record))`. Confirm helper names and the `TestAction` namespace against the installed version (`vendor/filament/actions/src/Testing`) before relying on them.

Swap infrastructure for fakes in feature tests through the container: `$this->app->instance(OrderNotifier::class, $spy = new SpyOrderNotifier());`.
