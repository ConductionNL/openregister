# Enforcing confidentiality (classification clearance)

OpenRegister filters objects by a classification level, such as the ZGW
`vertrouwelijkheidaanduiding`, when a schema declares an
`x-openregister-classification` block in its configuration. A schema without
the block behaves exactly as before.

The order of the levels is the schema's. OpenRegister holds no built-in
vocabulary: ZGW's eight levels, a security marking and a three-step internal
scale are the same mechanism configured differently.

## The block

```json
{
  "configuration": {
    "x-openregister-classification": {
      "property": "vertrouwelijkheidaanduiding",
      "levels": [
        "openbaar", "beperkt_openbaar", "intern", "zaakvertrouwelijk",
        "vertrouwelijk", "confidentieel", "geheim", "zeer_geheim"
      ],
      "clearances": [
        { "group": "zaakbehandelaars", "level": "zaakvertrouwelijk" },
        { "group": "juristen", "level": "geheim" }
      ],
      "default": "openbaar",
      "ceilings": [
        { "scope": "public", "atOrAbove": "vertrouwelijk" }
      ],
      "floor": { "property": "minimumVertrouwelijkheid", "sourceRelation": "documentType" }
    }
  }
}
```

| key | meaning |
|---|---|
| `property` | The object property carrying the label. Must be declared on the schema. |
| `levels` | The labels, least restrictive first. At least two, no duplicates. |
| `clearances` | Group to level mappings. The highest level among the caller's groups wins. |
| `default` | The clearance of a caller no mapping names. Without it, the lowest level. |
| `ceilings` | A scope that may never reach an object at or above a level, whatever a grant says. |
| `floor` | Where the least restrictive level a write may set is read: a property on the object, or behind one reference. |

The block is validated at schema save.

## What it enforces

- **Read above the caller's clearance is refused on both layers.** The find
  path (`Service/Rbac/ClassificationReadGate`, called from `PermissionHandler`)
  refuses the object. The list and aggregation paths (`MagicRbacHandler`, via
  `Service/Rbac/ClassificationListTerm`) compile the comparison into the query
  as an `IN` over the admitted labels, so the page size, the total and any
  count reflect only what the caller may see.
- **An absent, empty or unknown label ranks most restrictive**, and is logged
  on the find path. An unmapped caller takes the declared `default`.
- **A ceiling refuses its scope outright.** An anonymous caller is the `public`
  scope; no schema rule or per-object grant re-opens an object above the
  ceiling to it.
- **A write may raise a label and not lower it below the floor**, refused at
  save naming both levels.
- **Administrators are cleared to the top level**, the existing administrator
  bypass.

Classification only narrows: it is applied on top of the schema's
`authorization` block and the deny rules, never instead of them.

## Naming caveat

The same concept appears under several property names across schemas
(`vertrouwelijkheidaanduiding`, `confidentialityLevel`, `confidentiality`).
Point `property` at the name the schema actually declares; schema save refuses
a `property` the schema does not declare.
