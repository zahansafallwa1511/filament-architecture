# Refactoring business logic out of Filament

The goal of a refactor is **the same behaviour, in the right place**. Users of the admin panel should not notice anything changed — same validation messages, same notifications, same redirects, same emails. Behaviour changes are a separate decision the user makes.

## Steps

### 1. Inventory
Read the Filament file(s) and everything they reach: Resource, Pages, `Schemas/*Form.php`, `Tables/*Table.php`, RelationManagers, and the model's observers / `booted()` hooks / mutators (hidden logic often lives there). Run `scripts/audit.php` to get a first list.

List every place that **decides** or **changes** something:

| Location | What it does | Kind |
|---|---|---|
| `EditInvoice::afterSave()` | recalculates totals, applies 10% discount over £1000 | calculation |
| `EditInvoice::afterSave()` | emails the customer when status becomes `sent` | side effect |
| `InvoicesTable` action `markPaid` | sets `paid_at`, creates Payment row in `DB::transaction` | write + transaction |
| `InvoiceForm` `status` select | free status change | hidden transition |

### 2. Group into user intentions
Each row maps to what the user is *trying to do*: "edit invoice details", "send invoice", "mark invoice paid". One intention → one use case. Calculations shared by several intentions become a Domain service or value object (`InvoiceTotals::calculate(lines, discountPolicy)`), not a use case.

### 3. Protect behaviour (when practical)
If the project has a test suite, write or check a Livewire feature test for the current behaviour first (see `testing.md`). If there is no test suite, say so and list the behaviours you are preserving so the user can verify by hand.

### 4. Extract one intention at a time
For each intention:
1. Create the Input DTO from what the Filament code currently reads (`$data[...]`, `$record`, `auth()->id()`, `now()`).
2. Move the logic into the use case, replacing each infrastructure call with an injected interface (`now()` → `Clock`, `DB::transaction` → `TransactionManager`, `Mail::to()` → a notifier interface, `event()` → `EventPublisher`).
3. Move rules that the UI also needs (`visible()` conditions) to a Domain enum/value-object method, and call it from both places.
4. Replace the Filament code with the thin call + error mapping (`filament-patterns.md`).
5. Implement and bind the new interfaces in Infrastructure.
6. Unit test the use case.

Don't extract everything into one giant `InvoiceService` — that only moves the mess.

### 5. Report surprises instead of fixing them silently
While moving code you will find things like: an email that fires on every save rather than only on the transition, a discount applied twice, a status that can jump from `paid` back to `draft`. Preserve the current behaviour, and list these as **questions for the user** at the end. Fixing them is a product decision.

### 6. Finish
- Run the audit script again — Filament and Application should come back clean.
- Summarise: files created, files changed, behaviours preserved, open questions.

## Before / after

**Before** — `app/Filament/Resources/Invoices/Pages/EditInvoice.php`

```php
protected function afterSave(): void
{
    $invoice = $this->record;
    $subtotal = $invoice->lines->sum(fn ($l) => $l->quantity * $l->unit_price);
    $discount = $subtotal > 1000 ? $subtotal * 0.10 : 0;
    $invoice->update([
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => ($subtotal - $discount) * 1.2,
    ]);

    if ($invoice->wasChanged('status') && $invoice->status === 'sent') {
        Mail::to($invoice->customer->email)->send(new InvoiceSent($invoice));
        $invoice->update(['sent_at' => now()]);
    }
}
```

**After** (pragmatic mode)

```php
// app/Filament/Resources/Invoices/Pages/EditInvoice.php
protected function handleRecordUpdate(Model $record, array $data): Model
{
    try {
        return app(UpdateInvoiceDetails::class)->execute($record, new UpdateInvoiceDetailsInput(
            customerReference: $data['customer_reference'] ?? null,
            notes: $data['notes'] ?? null,
        ));
    } catch (DomainException $e) {
        Notification::make()->danger()->title($e->getMessage())->send();
        $this->halt();
    }
}

protected function getHeaderActions(): array
{
    return [
        Action::make('send')
            ->requiresConfirmation()
            ->visible(fn (Invoice $record): bool => $record->status->canBeSent())
            ->action(function (Invoice $record, Action $action, SendInvoice $sendInvoice): void {
                try {
                    $sendInvoice->execute($record);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Invoice sent')->send();
            }),
    ];
}
```

```php
// app/Domain/Invoice/InvoiceTotals.php — pure calculation, used by every use case that changes lines
final readonly class InvoiceTotals
{
    private const DISCOUNT_THRESHOLD = 1000;
    private const DISCOUNT_RATE = 0.10;
    private const VAT_RATE = 0.20;

    public function __construct(public float $subtotal, public float $discount, public float $total) {}

    /** @param iterable<array{quantity:int, unit_price:float}> $lines */
    public static function calculate(iterable $lines): self
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += $line['quantity'] * $line['unit_price'];
        }
        $discount = $subtotal > self::DISCOUNT_THRESHOLD ? $subtotal * self::DISCOUNT_RATE : 0.0;

        return new self($subtotal, $discount, ($subtotal - $discount) * (1 + self::VAT_RATE));
    }
}
```

```php
// app/Application/Invoice/UseCases/SendInvoice.php
final class SendInvoice
{
    public function __construct(private InvoiceNotifier $notifier, private Clock $clock) {}

    public function execute(Invoice $invoice): Invoice
    {
        if (! $invoice->status->canBeSent()) {
            throw InvoiceCannotBeSent::fromStatus($invoice->status);
        }

        $invoice->status = InvoiceStatus::Sent;
        $invoice->sent_at = $this->clock->now();
        $invoice->save();

        $this->notifier->invoiceSent($invoice->id);

        return $invoice;
    }
}
```

Questions raised for the user in this example: "Totals were recalculated on every save, even when lines didn't change — kept as-is by recalculating in `UpdateInvoiceDetails`; should they only recalculate when lines change?" and "The status select allowed any transition — replaced with a Send action; are there other transitions (void, mark paid) that need actions?"

Money note: if the project stores money as floats, keep floats during the refactor (behaviour preservation) and suggest integer minor units / a Money value object as a follow-up.
