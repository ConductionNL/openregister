# A fragment may put appointments on its objects

## Why
A case's Afspraken card lists the calendar appointments linked to it. An example set could not seed one, so DqZaakTaken showed none while its board draws "Toelichting stukken aan de verzoeker" (14 okt 10:00-10:30, stadskantoor balie 3, Sanne de Vries, Uitgenodigd).

## What changes
- A new `appointments` part. Each row names the object, the account whose calendar holds the event (`owner`, optionally `calendar`, default `personal`), the summary, start, end, location and description, and the invitees (`{name, email, status}`; status is invited, accepted, declined or tentative).
- The event is written through the CalDAV backend, and the calendar is written when the account has none yet. It is linked to the object as a linked appointment is.
- Remove deletes the event and the link. A calendar the set wrote stays, as the account's own.
