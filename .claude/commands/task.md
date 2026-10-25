Open task: $ARGUMENTS

Read `docs/PROJECT_INSTRUCTIONS.md` and `docs/PLAN.md` first. Do not write code
until every question below is answered in writing in this ticket.

## §6 pre-coding gate

1. Which tables / columns / roles / GUCs does this touch? If the data contract
   changes, update `docs/PLAN.md §2` first.
2. Which shared contracts (statuses, enums, DTOs) does it use or add? One source only.
3. Show the exact path by which tenant context reaches every query.
4. Security: authn, authz, input validation, rate limit, log redaction, secrets.
5. Scale: cost per request and per active session. What is bounded?
6. Failure: timeout, retry, duplicate, crash mid-way.
7. Research for anything version-specific, verified by running code — never from memory.

## Then

Implement on a branch named `task-N-<slug>`. Run the three gates until green against
real Postgres + Redis. State the task's own acceptance gate from `docs/PLAN.md §3`
and show the output that demonstrates each clause of it.
