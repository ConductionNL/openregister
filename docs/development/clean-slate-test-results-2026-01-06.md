# Clean Slate Test Results - 2026-01-06

## Doel
Testen of dubbele configuraties worden veroorzaakt door apps die configuraties inladen bij enablement.

## Test Setup
- Containers en volumes volledig verwijderd
- Fresh Nextcloud 32.0.1.2 installatie
- PostgreSQL database
- OpenRegister 0.2.9-unstable.11
- OpenCatalogi 0.7.2 (handmatig gekopieerd, niet gemount)
- SoftwareCatalog 0.1.135 (handmatig gekopieerd, niet gemount)

## Test Resultaten

### Test 1: OpenRegister Enable
**Verwachting**: Mogelijk maakt OpenRegister zelf configuraties aan.  
**Resultaat**: ✅ GEEN configuraties of registers aangemaakt bij enable.  
**Database checks**:
- 'oc_openregister_configurations': 0 rows
- 'oc_openregister_registers': 0 rows

### Test 2: OpenCatalogi Enable
**Verwachting**: OpenCatalogi zou mogelijk een eigen configuratie kunnen inladen.  
**Resultaat**: ✅ GEEN configuraties of registers aangemaakt bij enable.  
**Database checks**:
- 'oc_openregister_configurations': 0 rows
- 'oc_openregister_registers': 0 rows

### Test 3: SoftwareCatalog Enable
**Verwachting**: SoftwareCatalog zou mogelijk de magic configuratie kunnen inladen bij boot.  
**Resultaat**: ✅ GEEN configuraties of registers aangemaakt bij enable.  
**Database checks**:
- 'oc_openregister_configurations': 0 rows
- 'oc_openregister_registers': 0 rows

### Test 4: Manual Configuration Import
**Verwachting**: Handmatige import zou moeten werken.  
**Resultaat**: ❌ **IMPORT HANGT VOLLEDIG**

#### Symptomen:
1. Script laadt configuratie JSON succesvol
2. Script print: 'Config loaded: Software Catalog Register'
3. Script hangt daarna indefinitely
4. Geen errors in Docker logs
5. Geen data geschreven naar database
6. Script hangt zelfs **voordat** Configuration entity wordt aangemaakt

#### Pogingen:
- **Poging 1**: Via 'ConfigurationService->importFromApp()' → hangt
- **Poging 2**: Direct 'ImportHandler->importFromJson()' zonder Configuration entity → hangt
- **Poging 3**: Met Configuration entity aanmaken eerst → hangt **voordat** Configuration aangemaakt wordt
- **Poging 4**: Met 'ensureDependenciesForSeedData' uitgeschakeld → hangt nog steeds

## Root Cause Analyse

### Waarom Hangt Het?
Het script hangt **bij het resolven van services via DI**, niet tijdens de import zelf!

Wanneer we proberen:
```php
$importHandler = \OC::$server->get('OCA\OpenRegister\Service\Configuration\ImportHandler');
```

Triggert dit waarschijnlijk:
1. DI probeert 'ImportHandler' te resolven
2. 'ImportHandler' heeft dependencies op andere services
3. Die services triggeren mogelijk app boots
4. Die app boots proberen configuraties in te laden
5. Die configuratie imports proberen weer 'ImportHandler' te resolven
6. → **Infinite circular dependency loop** 🔴

### Bewijs:
- Script print 'Config loaded' maar hangt daarna meteen
- Geen enkele database interactie
- Geen errors (want het is geen exception, maar een infinite loop)
- Zelfs met dependency checks uitgeschakeld hangt het
- Het hangt **voordat** enige import logic draait

## Conclusie

### ✅ Bewezen:
1. **Apps maken GEEN configuraties aan bij enablement**
2. Het dubbele configuraties probleem komt NIET van app boot proces

### ❌ Niet Getest:
1. Of import proces dubbele configuraties zou maken
   - Onmogelijk te testen omdat import volledig hangt

### 🔴 Blocker Issue:
**Circular Dependency in DI Container**

Het dependency system dat we hebben gebouwd heeft een fundamenteel probleem:
- 'ImportHandler' wordt ge-resolve → probeert configs in te laden
- Config inladen → vereist 'ImportHandler'
- → Infinite loop

### Oplossing:
De guard flag en lazy loading die we implementeerden is onvoldoende.

Het probleem zit in de **DI registration zelf**, niet in de method calls.

Mogelijke oplossingen:
1. **Lazy service injection**: Gebruik closures in DI
2. **Event-based loading**: Gebruik Nextcloud events in plaats van directe service calls
3. **Deferred initialization**: Laad configuraties asynchroon na app boot
4. **Remove auto-loading**: Apps laden geen configuraties meer bij boot, alleen on-demand

## Volgende Stappen

1. Fix circular dependency in DI (priority: CRITICAL)
2. Re-test import process na fix
3. Test of dubbele configuraties ontstaan
4. Implement definitieve oplossing

## Files Modified

- '/openregister/docker-compose.yml': Removed 'opencatalogi' mount (2 occurrences)
- '/openregister/lib/Service/Configuration/ImportHandler.php': Disabled 'ensureDependenciesForSeedData' call

## Lessons Learned

1. Clean slate testing is essentieel voor het isoleren van problemen
2. Assumptions over waar bugs zitten kunnen misleidend zijn
3. DI circular dependencies zijn moeilijk te debuggen (geen error, alleen hanging)
4. Permission issues (600 vs 644) kunnen DI lookup problemen veroorzaken
5. Docker mount ownership (1000:1000) vs container user (33) is niet het probleem

