---
kind: code
---

# Proposal: omitted-unwritable-properties-are-kept

## Why

A PUT replaces the whole object: `SaveObject` fills every property the payload leaves out with null. Property
authorization (`PropertyRbacHandler::getUnauthorizedProperties`) only checks the properties a payload carries, so a
caller who may update the object but not a protected property could clear that property simply by leaving it out,
the very write that sending null is refused for. Found in review of dossiq's document access exception: anyone the
document's update rule admits could PUT without `restricted`, which nulled it, and the read rule then treated the
document as unrestricted; the exception history was erased the same way.

## What changes

- On update, every stored top-level property the payload omits and the caller may not update is carried forward
  from the stored object, through the same path that keeps write-only and unreadable values
  (`collectOmittedUnreadableProperties`, renamed `collectOmittedProtectedProperties`, now also names what the caller
  may not update; `restoreWriteOnlyValues` applies it).
- A principal who may update the property keeps PUT semantics: leaving it out still clears it.
- Administrators are unaffected, as for every property rule.
