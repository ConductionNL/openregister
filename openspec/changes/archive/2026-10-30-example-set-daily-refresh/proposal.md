# Proposal: example-set-daily-refresh

## Why

An example set's dates resolve once, against the day it is installed or refreshed. On a demo instance that is viewed on later days, "today" stops being true the next morning: dossiq's DqDagAfsluiten has no notes recorded today, DqMijnWerk no term ending today, DqInspectie no inspections today. OpenRegister has no read-time "now" token, and adding one would mean teaching every reader in every app to resolve it.

## What changes

An administrator may mark an installed set for a daily refresh (`occ openregister:example-set:install <set> --refresh --daily`; `--no-daily` stops it). A background job refreshes every marked, installed set once a day, which rewrites every declared date against that day: everything relative to "today" follows the day the instance is viewed. A marked set that is no longer installed is skipped, never installed by the job. Nothing is marked by default, so an instance with real data is never touched.
