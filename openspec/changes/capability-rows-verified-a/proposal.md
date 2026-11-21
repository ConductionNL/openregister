---
kind: docs
---

# Proposal: capability-rows-verified-a

## Summary

The 12 November verify round read every row of Open Register's capability matrix against the
scenarios of its recorded requirement. Rows 0 to 74 were checked in this lane. Three rows record
a requirement that says only part of what the row claims, and no other requirement says the rest.
This change writes the missing requirements, so each row records a heading whose scenarios say it.

## Rows this closes

| row | capability | what was missing |
|---|---|---|
| mod-field-types | Choose from rich field types such as email, URL, date, choice list or file, each with its own editor | the recorded requirement covers choice lists, files and languages; no scenario gives a date a date picker, a yes or no field a switch, or checks an email or web address |
| rec-public-form | Publish a form that outsiders fill in to create records, without an account | the journey requirements cover throttling and declaration; no scenario has an outsider open a form and create a record |
| srch-trail | See what users searched for and which searches found nothing | the trail requirements record each search; no scenario picks out the searches that found nothing |

## What changes

- ADDED requirement in `objects-crud`: dates, yes or no fields, email and web addresses get a fitting editor or check.
- ADDED requirement in `form-and-journey-registry`: an outsider fills in a published form and a record is created.
- ADDED requirement in `search-trail-recording`: the search trail picks out searches that found nothing.

The code does the first and the last today. The public form is not built yet; its row stays `building`.
