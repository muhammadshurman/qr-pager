# The per-line half of tools/gate/bin/check-shell-evidence, as ONE awk pass.
#
# ⚠ THIS FILE EXISTS FOR A PERFORMANCE DEFECT THAT BECAME A CORRECTNESS ONE. The checker
# did its comment- and quote-stripping with `printf '%s' "$line" | sed …` PER LINE: 3100
# lines across the scanned scripts, so 6200 subprocesses. That measured 4.35 s locally and
# 6.67 s on a GitHub runner, which is past Vitest's 5 s default timeout -- so a test that
# drives the checker FAILED IN CI while passing locally. A gate step slow enough to time
# out is a gate step that reports red for the wrong reason, which is the one thing a gate
# must not do.
#
# One awk invocation replaces all 6200.
#
# PORTABLE ON PURPOSE: no ENDFILE, because that is a GNU extension and GitHub's
# ubuntu-latest runner has mawk as `awk`. File transitions are detected by FILENAME
# changing and the last file is finalised in END -- which works in both.
#
# Variables passed in with -v:
#   strictlist  newline-separated files that MUST set pipefail (executable scripts; a
#               sourced helper inherits its caller's options and is exempt)

function flush_file(f) {
    # Called when a file is finished. An executable script that never enabled pipefail is
    # a finding: without it a pipeline's status is its LAST stage's.
    if (f != "" && strict[f] && !saw_pipefail[f]) {
        # A single quote needs no escaping inside an awk double-quoted string: there is no
        # shell here. The first version carried the shell form '\'' and gawk warned
        # "escape sequence \\' treated as plain '" on every run -- noise in a gate log is
        # how a real warning gets skimmed past.
        printf "%s\t1\tno 'set -o pipefail'\tan executable script must set it, or a pipeline's status is its LAST stage's\n", f
        findings++
    }
}

BEGIN {
    n = split(strictlist, parts, "\n")
    for (i = 1; i <= n; i++) {
        if (parts[i] != "") strict[parts[i]] = 1
    }
    cur = ""
    findings = 0
}

FILENAME != cur {
    flush_file(cur)
    cur = FILENAME
    prev = ""
    saw_pipefail[FILENAME] = 0
}

{
    code = $0

    # A whole-line or trailing comment is prose, not code. Stripped so this project's own
    # documentation of the hazard does not read as the hazard -- which it did: the fixture's
    # comment naming `set -o pipefail` once satisfied the pipefail check by itself.
    sub(/#.*$/, "", code)

    # SINGLE-quoted spans are removed. Inside single quotes a shell performs NO expansion,
    # so a `$?` there is literal text and a `|` is a literal character. This script's own
    # `case` patterns are written *'|'* and its messages are single-quoted strings holding
    # `$?`, so without this the checker reported two hazards in its own source.
    #
    # DOUBLE quotes are deliberately kept: they interpolate, so `echo "exit=$?"` IS a real
    # read and is exactly the shape being caught.
    gsub(/'[^']*'/, "", code)

    if (code ~ /set -[a-zA-Z]*o[a-zA-Z]* pipefail/ || code ~ /set -o pipefail/) {
        saw_pipefail[FILENAME] = 1
    }

    if (code ~ /\$\?/) {
        if (code ~ /\|/) {
            printf "%s\t%d\t$? on a line containing a pipe\tuse ${PIPESTATUS[0]}, or run the command without a pipe and capture $? on the next line\n", FILENAME, FNR
            findings++
        }
        if (code ~ /\$\(/) {
            printf "%s\t%d\t$? on a line containing a command substitution\tthe substitution runs FIRST and resets $?; capture the status into a variable before building the message\n", FILENAME, FNR
            findings++
        }
        # The PREVIOUS line containing a pipe, not merely ending in one. The first version
        # of this rule only caught a continued pipeline and so missed the exact mistake
        # that motivated the script:
        #
        #     node … --check | tail -5
        #     echo "exit=$?"
        #
        # The pipeline is complete on its own line, so nothing is continued; $? is still
        # tail's. `||` is masked first, since it contains a pipe and is not one.
        p = prev
        gsub(/\|\|/, "__OR__", p)
        if (p ~ /\|/) {
            printf "%s\t%d\t$? after a line containing a pipe\tthe status belongs to the pipeline LAST stage; use ${PIPESTATUS[0]}\n", FILENAME, FNR
            findings++
        }
    }

    prev = code
}

END {
    flush_file(cur)
    # The count goes to stderr-as-data on the last line, so the caller need not re-count.
    printf "FINDINGS\t%d\n", findings
}
