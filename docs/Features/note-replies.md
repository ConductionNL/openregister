
# Replies to notes

A note on an object can answer another note on the same object, so a
conversation stays together instead of being a flat list.

## Writing a reply

`POST /api/objects/{register}/{schema}/{id}/notes` takes an optional
`parentId`: the id of the note being answered.

```json
{ "message": "Yes, see the files tab", "parentId": 7 }
```

The reply is stored as a Nextcloud comment with that parent. A note posted
without `parentId` behaves exactly as before.

A reply is refused with `422` and the sentence
`The note you reply to is not on this record.` when the parent:

- is on another object,
- does not exist, or
- is a note the writer may not read (an internal note, for a caller who only
  sees public notes).

The three cases share one answer, so a refusal does not reveal whether a note
exists elsewhere.

A reply never shows more widely than the note it answers: a reply to an
internal note is internal, whatever visibility was asked for.

## Reading the conversation

Every note returned by the list, by a single read and by the create answer
carries:

| Field | Meaning |
| --- | --- |
| `parentId` | The note this one answers, or `null` for a top-level note. |
| `replyCount` | How many replies the note has. |
| `parentDeleted` | `true` when the note this one answers has been deleted. |

Deleting a note keeps its replies. They stay in the list with their
`parentId`, marked `parentDeleted`, so an interface can show them as replies to
a deleted note instead of hiding them.
