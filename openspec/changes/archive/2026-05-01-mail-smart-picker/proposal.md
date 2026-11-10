# Mail Smart Picker

## Problem
Users composing emails in Nextcloud Mail (or writing in any rich-text context that supports the Smart Picker, such as Text, Talk, or Collectives) have no way to search for and insert references to OpenRegister objects. They must manually copy-paste URLs or object identifiers, which is error-prone, breaks the preview experience, and creates no structured link between the mail and the data object. Given that OpenRegister is the data backbone for many Conduction apps (OpenCatalogi, Procest, Pipelinq, ZaakAfhandelApp, Software Catalogus), users frequently need to reference register objects in their communications.

## Proposed Solution
Implement a Nextcloud **Reference Provider** (Smart Picker integration) for OpenRegister that:

1. **Registers as a discoverable, searchable reference provider** so it appears in the Smart Picker modal across all Nextcloud apps that support rich references (Mail, Text, Talk, Collectives).
2. **Allows users to search OpenRegister objects** via the existing `ObjectsProvider` search provider, with optional filtering by register and schema.
3. **Resolves OpenRegister object URLs into rich reference previews** showing object title, schema, register, key properties, and last-updated timestamp.
4. **Provides a custom Vue widget** for rendering the rich object preview inline in the editor (card-style with icon, title, properties, and a link to the full object).
5. **Leverages the existing Deep Link Registry** so that previews link to the consuming app (e.g., OpenCatalogi) rather than the raw OpenRegister admin view when a deep link is registered.
6. **Supports public references** for objects in publicly-accessible schemas, enabling rich previews even for unauthenticated viewers.

This uses the standard Nextcloud `OCP\Collaboration\Reference` API (available since NC 25, searchable since NC 26) and requires no changes to the Mail app itself.
