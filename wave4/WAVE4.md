# Wave 4 addendum: the last references against the order

`BRIEF.md` and `wave3/WAVE3.md` apply in full. Package ids are U01 to U08, files in `wave4/packages/`, branch `cloud/mod-U<nn>-<topic>`.

These references survived three waves, so expect them to be the hard ones. This is the last pass, and every reference ends one of two ways:

1. **Removed** with an allowed move (an event, a port owned by the lower area, an id or Kernel value object, the class counting to the area whose work it does, or wiring in the owner's registrar). Same rules as BRIEF.md: no forbidden move to make a number go down.
2. **Kept, with its reason written down.** Add `openspec/changes/functional-areas-depend-in-one-direction/remaining/U<nn>.md` to your last PR, with one row per kept reference: source file, target file, why no allowed move fits (what you tried, what would break), and what change would let it go later. These rows are the input for the per-file ratchet's documented exceptions and for the public explanation of the score.

Before you start, read what the earlier waves said about your files (WAVE3.md point 2).
