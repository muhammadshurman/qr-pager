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
