# A fragment's flow may carry finished runs

## Why
A case page's Flows card reads the case's flow runs. An example set could only ship flow definitions, so every seeded case showed "Er is nog geen flow uitgevoerd" while its board draws the runs it went through (DqZaak: Ontvangstbevestiging versturen Gelukt, Statusbericht naar de inwoner Mislukt, Documenten uit dossier koppelen Gelukt).

## What changes
A `flows` entry may hold `runs`: finished runs (completed, stopped, dead_letter, failed) on a subject object, with the start and end time, who started it and the error. Each run is stored in the flow's organisation and is deleted with the flow on remove. A queued, running or waiting run is refused, because the flow worker would execute it.
