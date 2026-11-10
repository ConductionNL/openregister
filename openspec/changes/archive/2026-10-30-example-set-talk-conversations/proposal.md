# A fragment may put Talk conversations on its objects

## Why
DqZaakContact draws a held call on the Lindelaan case, "Gesprek met Sanne de Vries over de stukken" (4 okt 10:30 to 10:42, Afgelopen). An example set could not write a Talk conversation, so the Live gesprek card stayed empty.

## What changes
- A new `talkConversations` part. Each row names the `object`, the conversation's `name`, its `owner` and its `participants`. It may give `call` (`start`, `end`): a call that was already held.
- On install the set finds the owner's group conversation by name, or creates it with its participants.
- A new conversation gets the held call in its history the way Talk writes one: a `call_started` and a `call_ended` system message, the second with who joined and the duration.
- The room is linked to the object, as a linked conversation is (TalkLink, linked by the fragment's app).
- A second install keeps the conversation, and the call is written once.
- Remove deletes the link, and the conversation with its history when the set created it.
- Without Talk, the row is reported as not written.
