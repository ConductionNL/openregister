# A refresh writes a party link again where it is

## Why
A party link caches its party's name and e-mail address when it is written. A link from an earlier install kept what it cached then. On :8080, Marja Verbeek's link as chair of Buurtvereniging Oost has no e-mail address, although her person record holds one now. Party links were never refreshed.

## What changes
- `parties` joins the parts a `--refresh` writes again.
- A held link is written again where it is: the link store finds it by object, party and role, so nothing is taken away first.
- The cached name and e-mail address are read afresh, and a changed note, period or primary flag arrives.
