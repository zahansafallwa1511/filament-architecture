---
type: llm
focus: trace
weight: 1
---

Running scripts/audit.php --mode=strict on the resulting project reports zero errors for files under app/Application and app/Domain (no Illuminate or App\Models imports, no facades, no global helpers such as now() or auth()).
