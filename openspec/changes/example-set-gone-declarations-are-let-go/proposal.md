# Proposal: example-set-gone-declarations-are-let-go

## Why

REFRESH-8o on :8080 (`set install --refresh --daily` rc 2) reported three findings that no write failed:

- `Written but not kept: change pipelinq/ticket-degraaf-afvalpas-eerst-vandaag: slaDeadline`. pipelinq removed that change on 28 Oct and moved the deadline into the ticket's own declaration. The record still held the change, so every run proved the deadline the change wrote on its install day against the one the declaration writes today.
- `Not on the instance: read state dossiq/pieter-opende-kapvergunning-essen`. dossiq replaced that read state with another one on 29 Oct. The record still held it, and the proof still looked for it.
- `Written but not kept: change pipelinq/contract-sportvelden-contact: contactRef`. The change wrote `contactRef` where the contract had none (`had: false`). Until salesContract 1.3.0 reached the instance the field was not stored at all, so each refresh found it absent and wrote it again. Now the field reads back as null, which the refresh took for somebody's edit, so it never wrote it again and the proof reported it lost.

## What changes

- After a fragment's changes and engine rows (tasks, notes, read states and the other parts) are installed, the record lets go of every entry of that fragment's app that the fragment no longer declares. The proof and remove leave what it wrote alone, as they already do for a field a held change no longer sets, and the report names each under "Left as it is". Only fragments in the plan are looked at, so an app that is not enabled keeps its entries.
- A held change counts a field that reads back as null as not set, the same as a field the object did not have. A refresh writes such a field again.
