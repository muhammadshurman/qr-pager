Open task: $ARGUMENTS

Read `docs/PROJECT_INSTRUCTIONS.md` and `docs/PLAN.md` first — `§0` of PLAN tells you
where the project is right now. Do not write code until every question below is
answered in writing in this ticket.

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

## You are the BUILDER. You do not review.

**Never spawn, invoke or simulate the qa-reviewer or security-reviewer agents.**
Never review your own diff, and never write review findings.

`PROJECT_INSTRUCTIONS.md §11` requires each reviewer to run in a **separate agent
session that receives the diff and the ticket only — never the builder's reasoning.**
A reviewer you spawn inherits your context, so it inherits every justification you
already made for your own choices. It will agree with you. That is not a review, and
running one destroys the only independent check this project has.

The human starts both reviews, in their own sessions, after the PR is open.

## When your build is green

Stop. Post the gate evidence on the ticket, open the PR, and report back with:

- the branch name and commit
- the three gate results and the coverage number
- every bug you found and the regression test that fails without the fix
- anything you deliberately did **not** do, and why
- anything you found that contradicts `PROJECT_INSTRUCTIONS.md` or `PLAN.md`

Then stop and wait. **Do not tag `task-N`.** The tag waits on both reviews and on
every Critical and High being fixed (§11).

## If you find a contradiction

`§0` applies: **stop and report, never silently resolve.** A conflict with a rank-2
or rank-3 document is reported in the ticket with your measurement — you do not edit
those files yourself.
