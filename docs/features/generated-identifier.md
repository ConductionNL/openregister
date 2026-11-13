# Generated identifiers

## Overview

Declare a case number on the property that holds it, and every case gets one
when it is created. The number is unique, it is never handed out twice, and it
cannot be edited afterwards.

```json
{
  "identifier": {
    "type": "string",
    "title": "Case number",
    "x-openregister-generated": {
      "sequence": "case",
      "format": "Z-{year}-{seq:5}",
      "resetOn": "year"
    }
  }
}
```

The first case of 2026 is `Z-2026-00001`. The first case of 2027 is
`Z-2027-00001`.

## The three keys

| Key | Meaning |
|-----|---------|
| `sequence` | The counter this property draws from. Two schemas naming the same counter share it, so a register can number cases and complaints in one run |
| `format` | The value, with placeholders. `{seq:n}` is the number padded to n digits, `{year}` and `{month}` come from the creation time, and everything else is literal |
| `resetOn` | `never` for one counter for all time, `year` to restart each January. Defaults to `never` |
| `reserved` | A list of values and `/patterns/` no sequence may issue. Optional. See below |

A number past its padding grows rather than wrapping. The hundred-thousand-first
case of a five-digit format reads `Z-2026-100001`, not `Z-2026-00001`.

## What the schema save refuses

Three declarations are refused with 422, each because it would produce values
that look right:

- A placeholder nothing renders, such as `{jaar}`. It would ship as its own
  literal text, so every object carries the same characters where its number
  should be.
- A format with no `{seq}` in it. Every object gets the same identifier.
- `resetOn: year` on a format that does not render `{year}`. This one works for
  a year, and then re-issues every number from the year before.

The annotation also belongs on a string property. A generated identifier on an
integer is refused.

## Reserved values and patterns

Some numbers should never be handed out: a value kept for a special case, or a
run of numbers an old system already used. `reserved` lists them.

```json
"x-openregister-generated": {
  "sequence": "case",
  "format": "Z-{seq:4}",
  "reserved": ["Z-0666", "/^Z-00(0[0-9]|1[0-9])$/"]
}
```

An entry written between slashes is a regular expression matched against the
rendered value; any other entry is one exact value. When the counter reaches a
reserved value the sequence advances past it and issues the next one, so the
reserved value stays unissued for good (the skipped number is spent, never
handed out later). A pattern that matched every value would spin forever, so
after a thousand skips in a row the create is refused rather than looping.

A `reserved` entry that is not a non-empty string, or a pattern that does not
compile, is refused at schema save.

## Gaps are allowed, reuse is not

A number is taken before the object is written. A create that then fails leaves
that number unused, and nothing goes back to fill the hole.

That is deliberate. Reserving a number without burning it means holding a lock
across the whole write, and two people filing at once then wait on each other.
A missing number in a series costs nothing; two records carrying the same number
costs a lot.

Pending proposal 4.26 asks for gapless numbering. It is recorded as a
disagreement and is not resolved here.

## Frozen after it is issued

An update that changes a generated identifier is refused with 422, naming the
value that was issued. A number already quoted in a letter has to keep matching
the record.

An update that leaves the property out is an ordinary edit, not a renumbering,
and is allowed.

## Importing records that already have numbers

A create or an import that supplies its own value keeps it, and pushes the
counter past it. Import `Z-2026-00120` and the next generated case is
`Z-2026-00121`.

A value the format does not recognise is left alone and moves no counter. An
instance that numbered its cases by hand before this annotation existed is still
importable, and its old numbers are not this counter's to reason about.

The period comes from the value, not from the clock. An import landing in
January that carries last year's numbers advances last year's counter.

## Relation to the sequence calculation node

`x-openregister-calculations` already has a `sequence` operator, scoped to one
register and schema, and this change draws from the same counters table and the
same atomic reservation. Reach for the annotation when the property IS an
identifier: it is declared where the value lives, its counter can be shared by
name across schemas, and it is the only one of the two that freezes the value
and advances on import.

## Adding a case number to an app

Add the annotation to the property, save the schema, and create an object. There
is no code in the consuming app and no call to make:

```
POST /api/objects/cases/case   { "title": "A new case" }
→ { "title": "A new case", "identifier": "Z-2026-00001", … }
```

## Specification

`openspec/changes/generated-identifier/`, in the `computed-fields` capability.
