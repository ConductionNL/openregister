# A fragment may link mail to its objects

## Why
PqOrganisatie draws a mail linked to Buurtvereniging Oost: "6 okt 16:05 · e-mail, gekoppeld · Fatma Yildiz, Subsidieformulier en de lijst met documenten gestuurd." It shows in the client's activity and in its E-mails card. Both read OpenRegister's email links (`openregister_email_links`). An example set could not write one. A link is made from a message in someone's Nextcloud Mail, and a demo instance has no such message.

## What changes
- A new `emailLinks` part. Each row names the `object` and the mail as it shows: `subject`, `sender` and `mailDate`.
- The mail is either declared by its RFC `messageId`, or a real Nextcloud Mail message given by `mailAccountId` and `mailMessageId`, such as one a GreenMail test account synced.
- `linkedBy` names the account that linked it, one the set creates or adopts. Without it, the shipping app is the linker.
- Each row becomes one link on the object, with the object's register and schema. It is marked in its metadata with the source `example-set:<app>/<key>`.
- A declared mail has no Mail ids, so it is listed but does not open Mail.
- A second install writes nothing new. Remove deletes the links the set wrote.
