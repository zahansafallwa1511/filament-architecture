---
type: llm
focus: trace
weight: 1
---

Stripe is called through an interface declared in app/Domain or app/Application and implemented in app/Infrastructure (wrapping the existing App\Services\StripeClient); the use case does not reference StripeClient directly; the binding is registered in a provider listed in bootstrap/providers.php.
