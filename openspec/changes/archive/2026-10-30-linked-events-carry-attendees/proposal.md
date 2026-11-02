# A linked event lists the people invited

## Why
DqZaakTaken "Afspraken" draws "… · Sanne de Vries" with an "Uitgenodigd" pill. A linked appointment's row carried `attendees: []`: the link table holds no invitees, and the scan that does read them only finds events tagged with OpenRegister properties.

## What changes
`CalendarLinkService::getLinkedEvents` reads each linked event's invitees from the event itself: name (CN), address and participation (`needs-action`, `accepted`, `declined`, `tentative`). An event that cannot be read lists nobody.
