---
tags: [skill-creator]
max_turns: 60
timeout_seconds: 1200
allowed_tools: [Read, Glob, Grep, Skill, Write, Edit, Bash]
---

add a Refund button to the payments table in filament. admins type an amount in pounds (partial refunds are fine) but it can't be more than whats left to refund on that payment, and only payments from the last 60 days can be refunded. use our StripeClient to do the actual refund, save a refund record with who did it and the stripe refund id, and set the payment status to refunded or partially_refunded

Input files: composer.json, composer.lock, app/, bootstrap/, database/, tests/
