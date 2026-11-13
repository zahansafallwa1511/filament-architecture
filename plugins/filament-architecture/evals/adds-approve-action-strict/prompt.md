---
tags: [skill-creator]
max_turns: 60
timeout_seconds: 1200
allowed_tools: [Read, Glob, Grep, Skill, Write, Edit, Bash]
---

Add an Approve action to the orders table in our Filament admin. Only pending orders can be approved, the admin has to write a short note when approving, and we need to store who approved it and when. After approving, email the customer to let them know their order was approved.

Input files: composer.lock, CLAUDE.md, app/, bootstrap/, database/, tests/
