#!/usr/bin/env bash
# Shared machinery for every proof in this repo that counts a RED as evidence. Sourced,
# never run.
#
# ===================================================================================
# WHY THIS FILE EXISTS — the fourth round of Task 4, and the third signal that meant
# nothing
# ===================================================================================
#
# `bin/lease-revert-proof` removed `and attempts = ?` from three statements and left
# `$row->leaseIdentity()` in their binding arrays. Every case that reached a worker write
# then died on `SQLSTATE[HY093] Invalid parameter number` — and SIXTEEN of those
# exception-reds were counted, reported and published as regression coverage, in
# `PLAN.md`, in two transcript headers and in a test file's own comment. The revert-proved
# figure was stated as 15, then as 17, and the measured answer is 11.
#
# THE CONTROL WAS ALREADY WRITTEN DOWN. That script's header named two cases as ones that
# "MUST NOT MOVE", and under the broken revert both of them moved. Nobody checked. **A
# proof with a declared control is worth exactly what checking the control is worth**, so
# the checking is no longer left to a reader.
#
# It is the third time this task produced a signal that meant nothing — a test NAME that
# stated a contract its body never reached (QA `H-2`), a case whose comments described a
# theft it never performed (QA `M-6`), and now a revert whose reds were exceptions. All
# three were caught by a person reading, none by a gate. This file is the gate.
#
# ===================================================================================
# WHAT IT ENFORCES, on every run of every proof that sources it
# ===================================================================================
#
#   1. THE BASELINE IS GREEN.        A proof whose unmodified tree is already red is
#                                    measuring something other than its revert.
#   2. THE CONTROLS DO NOT MOVE.     Cases a script declares as unrelated to the thing
#                                    being reverted must stay green in EVERY revert. A
#                                    revert that makes an unrelated case red is a BROKEN
#                                    REVERT, not coverage.
#   3. A RED IS AN ASSERTION.        A red produced by a driver error, a binding arity
#                                    error, malformed SQL or a PHP fatal is the harness
#                                    breaking, not the guard being removed. It is
#                                    rejected as evidence and fails the run.
#
# Any one of the three failing makes the whole proof exit non-zero, because a proof that
# reports a number it cannot stand behind is worse than one that does not run.

# shellcheck disable=SC2034
REVERT_PROOF_SOURCED=1

# Case-name fragments that must stay GREEN in every revert. Set by revert_proof_init.
REVERT_PROOF_CONTROLS=()
REVERT_PROOF_SUITES=""
REVERT_PROOF_FAILURES=0
REVERT_PROOF_SCRATCH=""
REVERT_PROOF_LAST_OUTPUT=""

# Signatures that mean "the revert broke the program", not "the guard was removed".
#
# EACH ONE IS A FAILURE OF THE EDIT RATHER THAN OF THE CODE UNDER TEST: a placeholder
# without its binding (the defect this file exists for), SQL a sed mangled, or PHP that no
# longer parses or no longer type-checks. A legitimate revert changes BEHAVIOUR; none of
# these is behaviour.
#
# The scan is over the proof's raw test output. A test whose own source context happened
# to contain one of these strings would be a false positive; none does, and a false
# positive here stops a run rather than passing a bad one, which is the right direction
# for a check whose whole purpose is refusing to over-claim.
REVERT_PROOF_NOT_EVIDENCE=(
  'Invalid parameter number'
  'SQLSTATE\[HY093\]'
  'SQLSTATE\[42601\]'
  'syntax error at or near'
  'Parse error:'
  'Fatal error:'
  'ParseError'
  'ArgumentCountError'
  'Call to undefined method'
  'Call to undefined function'
  # ⚠ ADDED 2026-10-28, from Task 6's §11 security review (L-9). A revert that leaves a
  # variable undefined, or indexes something that is not there, raises ErrorException --
  # PHP's promoted warning -- and SIX of Task 6's revert A reds were exactly that, counted
  # and published as coverage before the review measured them. That is the lease proof's
  # defect, which this file exists to prevent, in a new costume: the published figure was
  # "8 cases red" and the number of genuine assertion reds was TWO.
  'ErrorException'
  'Undefined variable'
  'Undefined array key'
  'Undefined property'
  'TypeError'
  'ValueError'
)

# revert_proof_is_evidence <output-file>
#
# Is a RED in this output the ASSERTION failing, or the harness breaking? Returns 0 when
# it is evidence, 1 when it is not, and says which signature disqualified it.
#
# Exposed separately because not every proof in this repo is a suite-wide revert loop:
# bin/vacuous-assertion-proof breaks the world three different ways and asserts that one
# named case goes red each time, so it needs the same question answered about a single
# filtered run. The question is identical and so is the answer; only the shape differs.
revert_proof_is_evidence() {
  local output="$1" signature

  for signature in "${REVERT_PROOF_NOT_EVIDENCE[@]}"; do
    if grep -qE "$signature" "$output"; then
      echo "NOT EVIDENCE: the run contains '${signature}' — the harness broke, so the" >&2
      echo "  red says nothing about the assertion under test." >&2
      grep -nE "$signature" "$output" | head -2 | sed 's/^/    /' >&2
      return 1
    fi
  done

  return 0
}

# revert_proof_init "<suite paths>" "<control fragment>" ...
#
# The first argument is what `php artisan test` is given; the rest are the controls.
revert_proof_init() {
  REVERT_PROOF_SUITES="$1"
  shift
  REVERT_PROOF_CONTROLS=("$@")
  REVERT_PROOF_SCRATCH="$(mktemp -d)"

  # PAO_DISABLE, or laravel/pao prints one JSON line inside an agent session and the
  # per-case verdicts cannot be read at all (R-054, CLAUDE.md).
  export PAO_DISABLE=1
}

revert_proof_cleanup() {
  [ -n "$REVERT_PROOF_SCRATCH" ] && rm -rf "$REVERT_PROOF_SCRATCH"
}

# revert_proof_run <label> [baseline]
#
# Runs the suites, prints the per-case verdicts, and applies the three checks above.
# Pass the literal word `baseline` as the second argument to require a fully green run.
revert_proof_run() {
  local label="$1"
  local mode="${2:-revert}"
  local raw="$REVERT_PROOF_SCRATCH/$(echo "$label" | tr -cd '[:alnum:]')"

  # A stable name for the most recent run, so revert_proof_require_red can find it
  # without re-deriving the label's slug (added with that function, 2026-10-28).
  REVERT_PROOF_LAST_OUTPUT="$raw"

  echo "=== $label"

  # STRIP THE COLOUR FIRST, THEN FILTER. Pest writes the ✓ and ⨯ glyphs with an SGR
  # escape in front of them, so a grep anchored at ^\s+ matches nothing at all -- which
  # is how the first version of one of these scripts printed empty sections and looked
  # like a suite with no cases.
  #
  # THE RAW OUTPUT IS KEPT, which is the part that matters: the filtered view cannot show
  # WHY a case went red, and "why" is exactly what check 3 is about. The published
  # transcripts of these proofs are the filtered view, and that is how sixteen
  # exception-reds were published as coverage without the word HY093 appearing once.
  # shellcheck disable=SC2086
  php artisan test $REVERT_PROOF_SUITES >"$raw" 2>&1
  sed -i 's/\x1b\[[0-9;]*m//g' "$raw"
  grep -E '^\s*(✓|⨯|PASS|FAIL|Tests:)' "$raw" || true
  echo

  local red=()
  mapfile -t red < <(grep -E '^\s*⨯' "$raw" | sed 's/^[[:space:]]*⨯[[:space:]]*//; s/[[:space:]]*[0-9.]*s[[:space:]]*$//')

  # ---- check 3: is each red an assertion, or did the revert break the program?
  local signature
  for signature in "${REVERT_PROOF_NOT_EVIDENCE[@]}"; do
    if grep -qE "$signature" "$raw"; then
      echo "  ✗ NOT EVIDENCE: this run contains '${signature}', which is the harness" >&2
      echo "    breaking rather than the guard being removed. A red produced this way" >&2
      echo "    proves nothing about the fix. Repair the revert, not the count." >&2
      grep -nE "$signature" "$raw" | head -3 | sed 's/^/      /' >&2
      REVERT_PROOF_FAILURES=$((REVERT_PROOF_FAILURES + 1))
      echo
      return 1
    fi
  done

  # ---- check 1: the baseline must be green
  if [ "$mode" = baseline ] && [ "${#red[@]}" -gt 0 ]; then
    echo "  ✗ THE BASELINE IS RED (${#red[@]} case(s)). Everything below it would be" >&2
    echo "    measuring the baseline's own failure, not a revert." >&2
    printf '      %s\n' "${red[@]}" >&2
    REVERT_PROOF_FAILURES=$((REVERT_PROOF_FAILURES + 1))
    echo
    return 1
  fi

  # ---- check 2: the declared controls must not move
  local control line moved=()
  for control in "${REVERT_PROOF_CONTROLS[@]}"; do
    for line in "${red[@]}"; do
      case "$line" in
        *"$control"*) moved+=("$control") ;;
      esac
    done
  done

  if [ "${#moved[@]}" -gt 0 ]; then
    echo "  ✗ A CONTROL MOVED. These cases are declared unrelated to what this revert" >&2
    echo "    removes, so a revert that reddens one is a BROKEN REVERT and its reds are" >&2
    echo "    not coverage:" >&2
    printf '      %s\n' "${moved[@]}" >&2
    REVERT_PROOF_FAILURES=$((REVERT_PROOF_FAILURES + 1))
    echo
    return 1
  fi

  return 0
}

# Exit non-zero if any check failed. Call once, at the end, after the restore.
# revert_proof_require_clean_tree <file>...
#
# ⚠ REFUSE TO START ON A DIRTY TREE, and restore from GIT rather than from a copy taken at
# run time. From Task 6's §11 reviews (QA L-3 / security L-9), both of which measured the
# same thing from different directions: a proof took its restore copy with `cp` from the
# WORKING TREE, so when a concurrent session had already left a revert applied, the "restore"
# put the reverted file back and reported it byte-identical -- truthfully, to the wrong
# baseline. One reviewer's run then measured a tree carrying two reverts and reported the
# invariant case GREEN, which is the opposite of the published claim; a commit taken in that
# state would have shipped a panel that queries a tenant table.
#
# `git stash list` is not consulted and no lock is taken: this refuses rather than
# serialises, because two sessions editing one checkout is a situation to stop on, not to
# queue behind.
revert_proof_require_clean_tree() {
  local dirty
  dirty="$(git status --porcelain -- "$@" 2>/dev/null)"

  if [ -n "$dirty" ]; then
    echo "revert-proof: REFUSING TO RUN -- these files differ from the index/HEAD:" >&2
    echo "$dirty" >&2
    echo "  A revert proof must start from a known baseline. Commit or stash first." >&2
    echo "  (Task 6's reviews measured a run that restored a CONCURRENT session's revert" >&2
    echo "   and reported it byte-identical -- to the wrong tree.)" >&2
    return 1
  fi

  return 0
}

# revert_proof_restore <file>...
#
# Restore from git, not from a copy. The copy is what went wrong; HEAD cannot.
revert_proof_restore() {
  git checkout -- "$@" || return 1

  local dirty
  dirty="$(git status --porcelain -- "$@" 2>/dev/null)"

  if [ -n "$dirty" ]; then
    echo "revert-proof: restore FAILED -- files still differ after git checkout:" >&2
    echo "$dirty" >&2
    return 1
  fi

  return 0
}

# revert_proof_require_red <label> <case-fragment>
#
# ⚠ A REVERT THAT PRODUCES NO RED IS NOT EVIDENCE OF ANYTHING (QA L-4). `revert_proof_run`
# checked that the CONTROLS stayed green and that every red was an assertion; it never
# checked that the revert produced a red at all -- so a revert whose sed silently matched
# nothing passed, and the verdict still printed "every revert kept its controls green and
# every red was an assertion", which is true and vacuous.
#
# Each revert now declares the case that MUST go red, and the run fails if it stayed green.
revert_proof_require_red() {
  local label="$1" fragment="$2" output="$REVERT_PROOF_LAST_OUTPUT"

  if [ -z "$output" ] || [ ! -f "$output" ]; then
    echo "revert-proof: $label -- no run output to check for a required red" >&2
    REVERT_PROOF_FAILURES=$((REVERT_PROOF_FAILURES + 1))
    return 1
  fi

  # ⚠ THE FRAGMENT MUST BE SHORT, BECAUSE PEST TRUNCATES THE CASE NAME. The colour is
  # stripped first (revert_proof_run's own comment says why: an SGR escape sits in front of
  # the glyph, so a pattern anchored before it matches nothing) -- and then the ⨯ line
  # carries the case name cut to about 62 characters with an ellipsis, while the FAILED
  # summary line cuts it harder still. Measured here: a caller that passed a whole case
  # name got "the required case did NOT go red" for a case that HAD gone red, which is a
  # false alarm in the one direction this machinery must not produce. A fragment of a few
  # distinctive words survives both truncations.
  if sed 's/\x1b\[[0-9;]*m//g' "$output" | grep -F '⨯' | grep -qF "$fragment"; then
    echo "  required red PRESENT: [$fragment]"
    return 0
  fi

  echo "revert-proof: $label -- the required case did NOT go red: [$fragment]" >&2
  echo "  A revert that changes no outcome proves nothing; the edit probably matched nothing." >&2
  REVERT_PROOF_FAILURES=$((REVERT_PROOF_FAILURES + 1))
  return 1
}

revert_proof_verdict() {
  local name="$1"

  if [ "$REVERT_PROOF_FAILURES" -gt 0 ]; then
    echo "${name}: FAILED — ${REVERT_PROOF_FAILURES} run(s) produced something that is not evidence." >&2
    echo "Nothing in this run may be quoted as a revert-proved count." >&2
    return 1
  fi

  echo "${name}: every revert kept its controls green and every red was an assertion."
  return 0
}
