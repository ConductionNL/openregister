# An email recipient reads its address through a reference

## Why

dossiq's supplier portal (dossiq change `portal-contribution` task T3, decision 128) has to mail a supplier when a message, an expiring contract, a due invoice or a published tender concerns it. A supplier has no Nextcloud account and is no party on those objects. Its contact address sits on the supplier object, which every one of them points at through `supplierRef`. No recipient kind could reach it: `field` needs a uid, and `parties` reads contact links. A rule that declared a new kind failed `NotificationAnnotationValidator`, which refuses unknown kinds, so the whole schema failed at import.

Decision 156 says to build the dependency rather than park it.

## What changes

- New recipient kind `email`: `{kind: "email", field: "supplierRef.contactEmail"}`. `ReferencedAddressNotifier` follows up to three references as a system read, bounded to the triggering object's organisation. It asks integriq once for the resolved addresses, appends the unsubscribe link and sends through `EmailSender::sendToAddress()`.
- `AnnotationNotificationDispatcher` sends `email` entries next to `parties` entries. Each entry leaves a history row (`field:<path>`, with the outcome), and a delivered entry counts as reached for the reached-nobody check.
- `NotificationAnnotationValidator` accepts the kind and refuses four malformed shapes.
- `external-recipient-opt-out` gains REQ-ERO-007.
