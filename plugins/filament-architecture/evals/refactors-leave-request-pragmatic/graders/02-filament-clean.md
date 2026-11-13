---
type: llm
focus: trace
weight: 1
---

Running scripts/audit.php --mode=pragmatic on the resulting project reports zero errors for files under app/Filament (no model writes, DB::transaction, Mail:: or ->notify() left in Filament).
