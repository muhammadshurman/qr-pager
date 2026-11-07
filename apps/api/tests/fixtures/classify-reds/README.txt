FIXTURES FOR bin/classify-reds, AND WHAT EACH ONE IS FOR.

These are the shape Pest prints with PAO_DISABLE=1 -- captured from a real planted red on
2026-11-01 and then edited to carry each message under test. They are FIXTURES OF A
DOCUMENTED SHAPE, not captured runs of the failures they describe: no 08006 failure block
exists anywhere in this tree to capture, because R-191's reds were read and reported rather
than committed. That distinction is stated here rather than glossed, because a fixture a
reader believes is a capture is worse than one they know is a fixture.

The two NAME-matched cases are proven against REAL Pest output instead, by planting a red
in OutboxWorkerTest (on the list -> attributed) and in EnumContractTest (not matched ->
real). Those plants are in PLAN §4a with their classifier verdicts.

  r191-attributed.txt   08006 + "timeout expired" + the TestCase.php:112 frame, in a file
                        that appears on NO name list. Must be ATTRIBUTED to R-191: this is
                        the family whose identity is a random test, so the name cannot
                        match and only the signature can.

  r191-wrong-frame.txt  THE NEGATIVE CONTROL, and the most important file here. The same
                        08006 message, at a DIFFERENT frame -- a genuine connection defect
                        in application code rather than the suite's own TRUNCATE. Must come
                        out REAL. Without the frame condition the SQLSTATE alone would
                        swallow every connection defect this project ever writes, which is
                        precisely the over-attribution the list exists to prevent.

  mixed.txt             One attributed red and one real red in one run. Must exit 1, not 2:
                        a real red is never masked by an attributed one sitting beside it.

r191-moved-frame.txt -- added 2026-11-02, and it is a VERBATIM CAPTURE rather than a
  constructed shape, which the three fixtures beside it are not.

  It is the real failure block from `composer gate` at e761f2c during Task 10a:
  `PasskeyTest` struck by R-191's 08006 connect timeout, with the frame at
  `tests/TestCase.php:223`.

  WHAT IT PINS. R-191's matcher named `TestCase.php:112`, and Task 9 moved that call site to
  222 (46e3e52). From that commit on, every red of this family came out of `bin/classify-reds`
  as a REAL RED -- the verdict read "1 of 1 failure(s) are on no row" for a fault whose own row
  describes it exactly. A line-pinned frame goes stale whenever the file moves, and what it
  produces is an UNEXPLAINED red, which is the most expensive defect shape this project
  records.

  So this fixture fails if the frame pattern is ever pinned back to a specific line, and the
  `r191-wrong-frame.txt` control beside it fails if the pattern is widened to the point of
  matching any 08006 anywhere. The two together are what keeps the matcher honest.

r191-other-connection.txt -- added 2026-11-02, also a VERBATIM CAPTURE, and it is the fixture
  that proved the first repair still too narrow.

  Captured from `composer gate` at 94f5b5b: `PanelProblemPageTest`, 08006 timeout expired on
  connection **pgsql** (not pgsql_owner), on a `SELECT` of `users` (not a TRUNCATE), raised
  from `Connectors/Connector.php:67`.

  WHAT IT PINS. R-191's title describes the symptom as "on pgsql_owner at TestCase::setUp's
  TRUNCATE", and a matcher built from the title missed this one. The row's own
  `why_not_a_code_defect` measurement is about `connect()` stalling under host load, which is
  independent of connection and statement -- so the matcher now keys on the CONNECT PATH.

  A connect timeout cannot be caused by any code in this repository. That is what makes the
  wide pattern defensible, and `r191-wrong-frame.txt` is the control: an 08006 raised by a
  QUERY, at `app/Features/Pager/PagerSessions.php:204`, must stay REAL.

  Measured the same day: full `composer gate` 2 of 2 RED (two different tests, two different
  connections); bare `php artisan test` 2 of 2 GREEN at 1198 passed. The variable is host load.

r191-app-code-with-testcase-frame.txt -- added 2026-11-02 from §11 QA M-4, and it is the
  REVIEWER's own falsification committed as a control.

  An 08006 `timeout expired` raised from `app/Features/Pager/PagerSessions.php:204` -- app
  code, which MUST stay REAL -- whose trace also lists `tests/TestCase.php:318`, the way a
  real Pest block routinely does.

  WHAT IT PINS. The first widening of R-191's matcher keyed the frame on
  `(?:Connector|TestCase)\.php:\d+`, and classify-reds applies `frame` to the whole failure
  BLOCK rather than to the raising frame. So one TestCase line inside the block was enough to
  attribute an app-code connection defect to a host flake -- the dangerous direction, on a
  surface where the waved-off red could be a security test.

  r191-attributed.txt was DELETED in the same change rather than edited to fit: it was a
  pre-capture construction with a TestCase frame and no vendor frame, and it was the only
  reason `TestCase` was in the alternation at all. Editing a capture to match a matcher is the
  wrong direction; two verbatim captures beside it already carry the real connect frame.


r191-exhausted-retry.txt
------------------------
TASK 12. A VERBATIM CAPTURE OF AN EXHAUSTED RETRY -- the ONE shape of this family that can
still redden a run now that `tests/Support/RetryingPostgresConnector` absorbs the first
stall.

WHY IT HAD TO BE CAPTURED RATHER THAN REASONED ABOUT. The retrying connector re-throws an
annotated PDOException from `tests/Support/RetryingPostgresConnector.php`, which is a frame
this family's matcher does not name -- so the obvious prediction is that the thrown-from
frame replaces `Connector.php` in the block and `classify-reds` starts calling this a REAL
RED. That prediction is WRONG, and the capture is how it was settled: Pest renders the root
of the `$previous` chain, so `Connector.php:67` is still the `at` frame and the attribution
holds. `matcher_history` records this matcher going stale TWICE on exactly this kind of
assumption, which is why it was measured.

THE TEST NAME IN IT IS `ZzTempExhaustedTest`, A THROWAWAY THAT NO LONGER EXISTS, AND IT IS
LEFT THAT WAY ON PURPOSE. A real exhausted stall cannot be asked for, so a temporary test was
added that let one escape uncaught, the block was captured, and the test was deleted. Renaming
it to a plausible suite member was the first draft of this fixture and was reverted: this
file's own `matcher_history` says *"Editing a capture to match a matcher is the wrong
direction entirely"*, and the name is not what the matcher reads -- R-191 matches by SIGNATURE
and FRAME, never by test name, which is the whole reason this family needs a signature at all.

Reproduced with a listening socket that completes the TCP handshake and never answers the
startup packet, which makes libpq raise its own `timeout expired` in ATTR_TIMEOUT seconds:
see `tests/Feature/Gate/ConnectRetryTest.php` › silentPostgres().

MUST stay ATTRIBUTED (exit 2). If this ever comes out REAL, the annotation has broken the
attribution and the fix is the matcher, not the message.
