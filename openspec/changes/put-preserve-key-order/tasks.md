# Tasks: put-preserve-key-order

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Fix the write path

- [ ] 1.1 Trace the object save/update path and locate where JSON object-typed property keys are reordered; neutralise it so submitted key order is preserved (iterate submitted keys on default-merge, no `ksort`/canonicalisation on object values) (REQ-OBJ-KO-01)
  - Absent-key defaults are appended after submitted keys, never interleaved to reorder existing ones.

- [ ] 1.2 Ensure the read serializer re-encodes object-typed properties in stored order (no read-side re-keying) (REQ-OBJ-KO-01)

## 2. Verification

- [ ] 2.1 HTTP round-trip test: PUT an object with a known key order into an object-keyed property, read back, assert the exact key sequence; then reverse the keys, save, re-read, assert reversed (drag-reorder simulation) (REQ-OBJ-KO-01)
  - Run in the `nextcloud:34` container: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit`.

- [ ] 2.2 PUT-semantic guard: assert a non-changed sibling field survives the write while key order is being preserved (REQ-OBJ-KO-01)

Acceptance criteria:
- A drag-reorder of an object-keyed property persists across save and re-read.
- No object-typed property keys are reordered by validate/store/serialize.
- PUT-semantic carry-forward of unchanged fields is unaffected.
