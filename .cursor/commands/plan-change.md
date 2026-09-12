# /plan-change

Write a change record. Do not write code, tests, or changelog entries in this
command. The record is the contract everything downstream is checked against,
and writing it after the code defeats its purpose.

## What to produce

A file at `.change/CHG-<n>.yml` matching the schema in `.change/CHG-0001.yml`.
Use the next unused number.

## Steps

1. Ask what is changing and why, in the user's words. If they describe a
   solution, ask what breaks today if nothing changes. The answer goes in
   `release_note`, which a product manager reads and an engineer does not.

2. Decide `change_type`: `bug_fix`, `feature`, or `deprecation`.

3. Determine `target.component` from the paths involved. Set `target.paths` to
   the narrowest set that covers the work. Narrow is better: C4 fails anything
   outside these paths, and widening later is a deliberate act the reviewer sees.

4. Resolve `owner` by reading `.github/CODEOWNERS` and taking the most specific
   matching path. Do not type an owner from memory and do not carry one over
   from another record.

5. Ask the user which branch to target. Do not derive it.

   Symfony's maintained branch set is not machine derivable. The repository
   carries branches for 7.0 through 7.2 that are long out of support, and 5.4
   still receives security fixes until February 2029 under a sponsorship
   agreement while the project's own release page lists support as ended in
   November 2025. A bug fix belongs on the oldest maintained branch containing
   the bug, which is a judgement about where the bug was introduced. Ask.

6. For a deprecation, fill `deprecates` with the fully qualified symbol, its
   replacement, and the version. `since` and `target.branch` must match.

7. Write `acceptance`. Each criterion is one observable statement and the name
   of the test that will prove it. The tests will not exist yet. Name them
   anyway: C5 fails until they do, which is the point.

## Finish

Run `php bin/check-change.php .change/CHG-<n>.yml` and show the output. Expect
failures. The record describes work nobody has done yet, and a passing gate at
this stage would mean the record promises nothing.
