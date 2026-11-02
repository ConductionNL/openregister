# Proposal: audit-trail-not-in-filter

## Why

A history card that leaves out `read` entries and the System actor (nextcloud-vue `CnAuditTrailCard` with
`excludeActions: ["read"]`, `excludeActors: ["System"]`) filters in the browser after it has read at most five
pages of 100 entries. An object that is read often holds nothing else in its newest 500 rows: the Jeugdwet example
case e0b306d2 has 2,538 read rows and 67 System updates over its one `file.downloaded` row, so the side Historie on
DqSociaalDomein shows none of what a person did. The trail endpoint cannot help today: an array filter value such as
`action[notIn]=read` is cast to the string "Array" and eq-matched, so it answers nothing.

## What changes

- `AuditTrailMapper::findAll` (and so every list and count built on it, including
  `GET /api/objects/{register}/{schema}/{id}/audit-trails` for admins and readers) takes a list filter:
  - `field[notIn]=a,b` (or `field[notIn][]=a&field[notIn][]=b`) leaves out the rows whose column is one of the
    values, compared case-insensitively, in the query, so the page and the total count what is left;
  - a plain list (`field[]=a&field[]=b`) keeps only the rows whose column is one of the values;
  - any other operator is refused (`InvalidArgumentException`, a 400 on the admin route) instead of being read as
    no filter or as the string "Array".
- The columns stay the existing allowlist.

## Scope

Generic and additive: a caller that sends no array value gets the same answer as before. The nextcloud-vue half
(`CnAuditTrailCard` sends `action[notIn]` and `user[notIn]` for its exclusions, on its own trail and on each
`relatedSources` trail) lands after this.
