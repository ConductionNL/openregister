# Favourites and recently opened objects as index lenses

## Why

Round 2 of the dossiq competitor analysis (row B06 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10): OpenCase has a Favourites page fed by a star on the case and a Recent
page fed by a per-user view history (`opencase/round2/pages/Favourites.md`,
`Recent.md`); Zaaksysteem has favourite case types on its dashboard and a
Favoriet saved search (`xxllnc-zaken/round2/pages/LegacyDashboard.md`,
`search-anatomy.md`).

dossiq has zero hits for favourite or recent. A star is per user and per
object, not a property of the object, so it is platform state: writing it
into the object would change the object's audit trail and version for every
reader. OpenRegister already keeps per-user, per-object interactions
(object-interactions: notes, tasks, tags) and is the place for two more.

## What changes

- A per-user star on any object: `PUT` and `DELETE
  /api/objects/{register}/{schema}/{id}/favourite`, stored outside the object
  and its audit trail.
- A per-user view history: opening an object's detail records a view
  (user, object, time), kept to the last 100 per user.
- Two index lenses the object query understands: `_favourite=true` and
  `_recent=true` (ordered by last view), so a consuming app's index page adds
  a chip with no code.
- A `favourite` marker on object reads so a detail page can render the star's
  state without a second call.

## Who benefits

dossiq (Cases page chips Mine, Favourites, Recent), zaakafhandelapp,
opencatalogi, stackiq, keepiq, and every index page in the fleet.

## Impact

- Affected specs: object-interactions (delta).
- Affected code: two new tables (`openregister_favourites`,
  `openregister_object_views`) with a migration, `lib/Service/Interaction/`,
  `lib/Controller/ObjectsController.php` (marker and lenses), the object
  query's filter parser.
- Backwards compatible: the object payload is unchanged; the marker sits in
  `@self`.
