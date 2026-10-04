# ADR-0006: Sequence milestone evidence without waiving release acceptance

**Status:** accepted by the project Owner on 4 Oktober 2026

## Context and authorization

The original M1 engineering gate required every assertion in TC-01–10 before M2 could begin. Full TC-03 needs renewal M2 and account backup/download M3; TC-05 needs destination delivery history M2; TC-07 needs an independent watchdog M4/M5. TC-08 credentialed quota and TC-10 destination coalescing also depend on later work. Requiring these before their implementations creates a circular dependency.

The Owner explicitly approved the proposal to evaluate M1's registry, observation, incident, freshness, security and demo scope, while retaining all full product scenarios in their owning milestones and the Internal v1 release gate. This approval authorizes sequencing documentation and M1 closure; this work stops before M2. It does not authorize a live probe, bot, connector or production operation.

## Decision

M1 is `MILESTONE_READY` only when every M1 assertion in the [plan's scenario allocation](../IMPLEMENTATION_PLAN.md#pembagian-bukti-tc-0110) has deterministic evidence, policy/data/security tests pass, and the fake shared-resource/stale-scheduler vertical demo is proven. Existing milestone acceptance criteria remain unchanged. This status is local engineering readiness, not full TC completion or Internal v1 readiness.

Later assertions remain unfinished until demonstrated by their owners:

- TC-03 canonical renewal reminder: `IP-M2-02`; account backup/impact/download: `IP-M3-04`–`08` and `IP-M3-10`.
- TC-05 destination recovery only after recorded down delivery: `IP-M2-06`/`08`/`09`.
- TC-07 independent-watchdog contract and failure-domain evidence: `IP-M4-05`/`07`, then provision/operational validation `IP-M5-02`/`04`.
- TC-08 credentialed quota/capability evidence: `IP-M3-01`/`02`/`10`; unknown quota never becomes a fabricated percentage.
- TC-10 destination flapping/coalescing: `IP-M2-06`/`08`/`09`.

M2/M3/M4 exit gates explicitly require their allocated deterministic assertions in addition to their original requirements. M5 still requires complete TC-01–44 evidence, sandbox/live validation where prescribed, and the Owner's readiness review. A partial full scenario cannot be labelled pass merely because M1's part passed. Independent-watchdog evidence from a separate failure domain remains mandatory; a simulated stale heartbeat cannot replace it.

## Consequences and evidence

Task IDs, requirement mappings, task outputs, all existing milestone acceptance bullets, DEC-01–14, full PRD scenarios and security/live constraints are preserved. The PRD milestone summary and plan gates are explicitly amended under this decision. The [CHECKPOINT](../CHECKPOINT.md#14-keputusan-owner-dan-penutupan-m1--4-oktober-2026) records evidence, outstanding full scenarios, commit/push and next step; this ADR is a decision record, not another ledger or runbook.

The unchanged source has a complete local MySQL result of 71 tests/392 assertions plus build/Pint/Composer checks and historical browser/implementation-CI evidence. Documentation validation verifies preserved acceptance and links; no new application, browser or live result is inferred from this decision.
