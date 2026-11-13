---
type: llm
focus: trace
weight: 1
---

The rule that only pending orders can be approved is enforced inside the use case or a Domain class (throwing a DomainException subclass), not only through visible()/hidden() in Filament.
