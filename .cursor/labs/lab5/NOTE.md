# Lab 5 · Block an edit with a hook

Forbidden character for this lab: U+00A7 SECTION SIGN (the section-sign glyph).

The `afterFileEdit` hook in `.cursor/hooks.json` calls
`.cursor/hooks/lab5-block-forbidden.sh`, which exits `2` when an edit under
`.cursor/labs/lab5/` has that character in `new_string`.

## Demo notes

Watch for whether the agent tells you it was blocked or silently works around it. This determines how you present the boundary story.

Target file for the probe: `probe.md` (start without the forbidden character).

safe: yes
