#!/usr/bin/env python3
"""RE-DERIVE PLAN.md §5.2's OWNER TABLE. One command, no typed figures.

    python3 tools/plan/ledger-sweep.py          # from the repository root

§0's own correction says it best: "a figure anyone can re-derive in one command is the
only kind this row should carry" -- written after that cell went stale five times in one
task. §5.2 is a per-owner table over 190 open rows, which is the shape that goes stale
fastest, so the table ships with its instrument.

TWO THINGS THIS SCRIPT HAD TO LEARN, both of them properties of the file rather than of
the question, and both worth knowing before trusting any count from it:

 1. §5 IS NOT ONE TABLE. Rows R-001..R-171 carry seven columns -- id, risk, SEVERITY, why
    accepted, compensating control, owner, review-by. Rows R-172..R-200 carry FOUR: id,
    risk, owner, review-by. So severity, "why accepted" and "compensating control" are
    absent from every row Task 8a's and 8b's reviews produced. Positional parsing from the
    left is therefore wrong for 29 rows, which is why owner and review-by are read from
    the END of the row and severity is found by scanning cells for the word.

 2. SOME CELLS CONTAIN PIPES. R-186's risk cell embeds a grep whose character class is
    `[0-9]{3}` inside a `|`-separated command, and R-197's embeds `(?:linux|darwin)`. Those
    rows split into 11 and 9 cells, so even reading from the end is wrong for R-186 -- it
    is handled by name below rather than silently mis-attributed, because a sweep that
    quietly gets one row wrong is worse than one that says which row it cannot read.

OWNER PRECEDENCE, and it matters: a BOLD `**Task N**` wins, then `**Muhammad**`, then
`**The builder**`, then an unbolded `Task N`. Without that precedence R-199 parses as
"Task 0", because its owner cell is `**Muhammad**` followed by the prose "the same
provenance §4a's Task 0 row ... and Task 1's row already rest on". That was a real
mis-parse on this script's first run, not a hypothetical.
"""

import re
import collections

SEVRE = re.compile(r'^\*{0,2}(Critical|High|Medium|Low)\b', re.I)
DELIVERED = {0,1,2,3,4,5,6,7,8}   # tagged tasks: task-0 .. task-8b
rows = {}

for line in open('docs/PLAN.md', encoding='utf-8'):
    m = re.match(r'^\|\s*\*\*(R-\d{3})\*\*\s*\|', line)
    if not m: continue
    rid = m.group(1)
    if rid in rows: continue
    cells = line.rstrip('\n').rstrip().rstrip('|').split('|')

    sev = None
    for c in cells[2:]:
        mm = SEVRE.match(c.strip())
        if mm:
            sev = mm.group(1).capitalize(); break

    owner_cell = cells[-2].strip()
    byc = cells[-1].strip()
    # R-186's risk cell carries pipes from an embedded grep, so its last-two are not
    # owner/review-by. Handled explicitly rather than silently mis-parsed.
    if rid == 'R-186':
        owner_cell, byc = '**Muhammad**, at the rebase', '2026-11-01'

    risk = cells[2].strip()
    closed = bool(re.search(r'(✅\s*)?CLOSED', risk[:220], re.I))

    bold = re.findall(r'\*\*Task (\d+)', owner_cell)
    if bold:
        owner = 'Task ' + bold[0]
    elif re.search(r'\*\*Muhammad', owner_cell):
        owner = 'Muhammad'
    elif re.search(r'\*\*The builder', owner_cell):
        owner = 'The builder'
    else:
        plain = re.findall(r'^[^A-Za-z]*Task (\d+)', owner_cell) or re.findall(r'Task (\d+)', owner_cell)
        if plain: owner = 'Task ' + plain[0]
        elif 'Muhammad' in owner_cell: owner = 'Muhammad'
        else: owner = 'UNATTRIBUTED'

    rows[rid] = dict(sev=sev, owner=owner, dates=sorted(set(re.findall(r'(20\d\d-\d\d-\d\d)', byc))),
                     closed=closed)

open_rows = {k: v for k, v in rows.items() if not v['closed']}
print(f"§5 rows: {len(rows)} total, {len(rows)-len(open_rows)} closed, {len(open_rows)} OPEN")
nosev = sorted(k for k, v in open_rows.items() if v['sev'] is None)
print(f"OPEN rows with NO SEVERITY STATED: {len(nosev)}")
print('  ', ', '.join(nosev))
print()

by = collections.defaultdict(list)
for rid, r in open_rows.items():
    by[r['owner']].append(rid)

def key(o):
    m = re.match(r'Task (\d+)', o)
    return (0, int(m.group(1))) if m else (1, o)

print(f"{'owner':<13}{'open':>5}{'Crit':>5}{'High':>5}{'Med':>5}{'Low':>5}{'UNST':>6}  earliest review-by")
for o in sorted(by, key=lambda k: -len(by[k])):
    ids = by[o]; c = collections.Counter(rows[i]['sev'] for i in ids)
    ds = sorted({d for i in ids for d in rows[i]['dates']})
    print(f"{o:<13}{len(ids):>5}{c['Critical']:>5}{c['High']:>5}{c['Medium']:>5}{c['Low']:>5}{c[None]:>6}  {ds[0] if ds else '(none stated)'}")

print()
remaining = {o: v for o, v in by.items() if (re.match(r'Task (\d+)', o) and int(re.match(r'Task (\d+)', o).group(1)) not in DELIVERED)}
tot = sum(len(v) for v in remaining.values())
print(f"REMAINING tasks (9-15) hold {tot} of {len(open_rows)} open rows; mean {tot/len(remaining):.1f} per remaining task")
print('DELIVERED-task owners still holding open rows (a delivered task cannot do the work):')
for o in sorted(by, key=key):
    m = re.match(r'Task (\d+)', o)
    if m and int(m.group(1)) in DELIVERED:
        print(f"   {o}: {len(by[o])} -> {', '.join(sorted(by[o]))}")
