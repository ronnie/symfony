# Why the target branch is asked for, not derived

Step 5 of the plan-change skill asks the user which branch to target. This
file holds the reasoning that used to sit in the step itself.

## The original instruction

Symfony's maintained branch set is not machine derivable. The repository
carries branches for 7.0 through 7.2 that are long out of support, and 5.4
still receives security fixes until February 2029 under a sponsorship
agreement while the project's own release page lists support as ended in
November 2025. A bug fix belongs on the oldest maintained branch containing
the bug, which is a judgement about where the bug was introduced. Ask.

## Where the evidence lives

Do not restate the numbers here; they go stale. Read them at the source:

- `conventions.yml`, record C3, `measured` and `design`. The canonical source
  (`https://symfony.com/releases.json`) exposes three overlapping keys,
  `supported_versions`, `maintained_versions` and
  `security_maintained_versions`, with three different answers. C3 asserts
  only that the declared branch equals the pull request's actual base; it
  encodes no branch policy, and the branch decision is deliberately left to a
  human in this skill.
- `DECISIONS.md`, the row "C3 checks self-consistency only".
- `.github/PULL_REQUEST_TEMPLATE.md`, line 3: "8.2 for features / 6.4, 7.4,
  8.1 for bug fixes". Nothing enforces it and the numbers age with the release
  cycle, which is why the deprecation and feature templates in `.change/` call
  the branch an assumption until confirmed.
- `.agents/skills/pr-review-merge-prep/SKILL.md`, line 19, states the rule
  without naming a key: bug fixes to the oldest affected maintained branch,
  behaviour changes and features to the development branch.

## What the default means

When nobody answers, the skill writes the checked out branch and marks it
`pending confirmation`. That is the same stand-in the change record templates
describe for an automation draft: an assumption a human can see and overturn,
not a decision the agent made on their behalf.
