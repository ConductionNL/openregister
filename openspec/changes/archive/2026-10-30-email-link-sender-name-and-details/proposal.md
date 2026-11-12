# An email link carries the sender's name and the details its row shows

## Why

A case's linked mail is listed by its subject and sender. The boards (dossiq DqZaakEmail) draw each mail as "Van Sanne de Vries · 5 okt 09:15 · 1 bijlage · automatisch gekoppeld via het Woo-postvak" with an "Als pdf gearchiveerd" pill, and an outgoing one as "Aan Lars de Koning". An email link holds only the sender's address (`sanne.devries@…`), so the list could show neither the name nor the initials (SV), and nothing records the direction, the attachments, how the mail was linked or its archive copy.

## What changes

- `openregister_email_links.sender_name` (migration Version1Date20261028180000). Linking a Mail message keeps the From recipient's label Mail holds; an example set may write it.
- Six link details, read from the link's `metadata`: `direction` (inbound/outbound), `recipientName`, `attachmentCount`, `linkedVia` (manual/mailbox/file), `archivedAs` (such as pdf) and `mailboxName` (the shared mailbox a mail came in through, "het Woo-postvak"). The JSON has `senderName` and `details` (those six keys, null when absent); `metadata` keeps its string shape.
- Example sets (`emailLinks`, #5002) may write `senderName` and the six details, checked before install.
