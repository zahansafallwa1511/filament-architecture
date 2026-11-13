---
type: llm
focus: trace
weight: 1
---

Existing behaviour is preserved: approval deducts the leave days from the employee's balance in a transaction (via an injected transaction interface) and notifies the employee with LeaveApproved; rejection stores the reason and emails the employee with the manager in CC using LeaveRejectedMail; decided_by and decided_at are recorded in both cases.
