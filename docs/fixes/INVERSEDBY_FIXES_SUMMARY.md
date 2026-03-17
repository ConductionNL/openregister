# InversedBy Relaties Fixes - Samenvatting

## Probleem
De `saveObjects` methode in `ObjectService.php` verwerkte inversedBy relaties niet correct tijdens bulk import operaties uit CSV bestanden.

## Geïdentificeerde Problemen

### 1. Onvolledige `prepareObjectsForBulkSave` methode
**Voor:**
```php
// For now, just return the object as-is since SaveObject handler doesn't have prepareObjectForBulkSave
// TODO: Implement proper object preparation through SaveObject handler
$preparedObjects[$index] = $object;
```

**Na:** 
- Implementeert pre-validatie cascading
- Genereert UUID's waar nodig
- Roept `handleBulkInverseRelations` aan
- Cacht schemas voor performance

### 2. `handleBulkInverseRelations` werd niet gebruikt
**Voor:** Methode bestond maar werd nooit aangeroepen

**Na:** 
- Aangeroepen in `prepareObjectsForBulkSave`
- Verbeterd om te werken zonder `writeBack` vereiste
- Verwerkt inverse relaties binnen de batch

### 3. BulkController gaf geen context door
**Voor:**
```php
$savedObjects = $this->objectService->saveObjects($objects);
```

**Na:**
```php
$savedObjects = $this->objectService->saveObjects(
    objects: $objects,
    register: $register,
    schema: $schema,
    rbac: true,
    multi: true,
    validation: true,
    events: false
);
```

### 4. Ontbrekende post-save writeBack
**Voor:** Geen writeBack verwerking na bulk save

**Na:** 
- Nieuwe `handlePostSaveInverseRelations` methode
- Gebruikt SaveObject handler's writeBack functionaliteit
- Verwerkt alleen properties met `writeBack: true`

## Verbeterde Workflow

1. **Preparation Phase:**
   - Schema caching
   - UUID generatie
   - Pre-validatie cascading
   - Bulk inverse relations verwerking

2. **Save Phase:**
   - Transform naar database formaat
   - Bulk save operatie
   - Object categorisatie (saved/updated)

3. **Post-Save Phase:**
   - WriteBack verwerking voor properties met `writeBack: true`
   - Error handling per object

## Test Resultaten

### CSV Structuur Analyse
- `organisatie.csv` heeft `deelnemers` kolom (meestal leeg)
- Schema heeft `deelnemers` met `inversedBy: "deelnames"` en `writeBack: true`
- `deelnames` property heeft geen inversedBy configuratie

### API Test
- Bulk save API werkt nu correct
- Objecten worden daadwerkelijk opgeslagen
- InversedBy relaties worden verwerkt in beide richtingen

## Impact op Import Functionaliteit

### Voor de Fixes:
- CSV import creëerde objecten maar inversedBy relaties werkten niet
- Bulk save leek succesvol maar sloeg geen objecten op
- Gerelateerde objecten hadden geen terugverwijzingen

### Na de Fixes:
- CSV import verwerkt inversedBy relaties correct
- Bulk save slaat objecten daadwerkelijk op
- WriteBack operaties werken voor bidirectionele relaties
- Performance verbeterd door schema caching

## Aanbevelingen

1. **Monitor Performance:** De nieuwe schema caching en bulk verwerking zou performance moeten verbeteren, maar monitor bij grote imports

2. **Test Thoroughly:** Test verschillende inversedBy configuraties:
   - Met en zonder writeBack
   - Single object vs array relaties
   - Verschillende schema combinaties

3. **Documentation Update:** Update documentatie over inversedBy configuratie en bulk import gedrag

4. **Error Handling:** Verbeter error reporting voor failed inversedBy operations

## Gebruiksvoorbeeld

```json
{
  "objects": [
    {
      "@self": {"register": 19, "schema": 105, "id": "org-1"},
      "naam": "Organisatie 1",
      "type": "Gemeente"
    },
    {
      "@self": {"register": 19, "schema": 105, "id": "org-2"},
      "naam": "Organisatie 2", 
      "type": "Gemeente",
      "deelnemers": ["org-1"]
    }
  ]
}
```

**Resultaat:**
- `org-1` wordt aangemaakt
- `org-2` wordt aangemaakt met `deelnemers: ["org-1"]`
- `org-1` krijgt automatisch `deelnames: ["org-2"]` via writeBack

## Conclusie

De saveObjects methode verwerkt nu correct inversedBy relaties tijdens bulk import operaties. De fixes zorgen ervoor dat CSV imports met gerelateerde objecten correct functioneren en dat bidirectionele relaties automatisch worden onderhouden.
