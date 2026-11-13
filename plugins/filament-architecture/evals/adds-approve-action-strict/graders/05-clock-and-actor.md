---
type: llm
focus: trace
weight: 1
---

The approval timestamp comes from the injected Clock interface and the approver's id is passed into the use case (e.g. via the input DTO); the use case does not call auth() or now().
