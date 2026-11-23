---
title: "Componentanalyse van de backend"
description: "Waarom de mappen in lib/ geen componenten zijn, welk Nextcloud-patroon daarachter zit, en hoe we de verstrengeling meten als componenten uit bestanden in verschillende mappen bestaan."
sidebar_position: 4
---

# Componentanalyse van de backend

Deze pagina legt uit hoe je de verstrengeling van de OpenRegister-backend meet. Eerst het probleem: twee beoordelingen die ver uit elkaar liggen. Daarna de oorzaak, het patroon dat Nextcloud voorschrijft, en een meetmethode die bij dat patroon past. Elk getal komt uit een script in deze repository. Je kunt het op elke commit draaien en krijgt dezelfde uitkomst.

## Het probleem: twee beoordelingen van dezelfde code

Bij de beoordeling van onderhoudbaarheid tellen twee maten per component:

- **Componentonafhankelijkheid**: het deel van de code dat geen andere component aanroept, de verborgen code.
- **Componentverstrengeling**: hoeveel lijnen er tussen componenten lopen, en welk deel daarvan de structuur breekt. Een lijn breekt de structuur als er ook een lijn terug loopt, als hij een kring sluit, of als hij een pad herhaalt dat er al is. Het gewicht van die lijnen gedeeld door het totaal heet de schendingsgraad.

Wat een component is, bepaalt dus de uitkomst. Wij hebben de mappen in de hoofdmap van de repository als componenten opgegeven: `lib/` is de backend, `src/` de frontend. [ADR-014](https://github.com/ConductionNL/openregister/blob/main/openspec/architecture/adr-014-sig-components-are-top-level-directories.md) legt die keuze vast. SIG keek in `lib/` en nam elke map daaronder als component.

Ons eigen schattingsinstrument bootst de beoordelingsregels zo goed mogelijk na. Op main van 9 november geeft het:

| Wat telt als component | Verborgen code | Onafhankelijkheid | Verstrengeling |
|---|---|---|---|
| De mappen in de hoofdmap (`lib/`, `src/`, `appinfo/` ...) | 100% | 5,5 sterren | 5,5 sterren |
| Elke map onder `lib/` (`Controller`, `Service`, `Db` ...) | 89,7% | 2,6 sterren | 3,9 sterren (schendingsgraad 0,060) |

Met de backend als één component is er niets te meten: alle verwijzingen blijven binnen `lib/`. Met de mappen onder `lib/` zakken beide maten flink. SIG kwam op die tweede indeling tot een negatief oordeel over de verstrengeling.

Beide uitkomsten zeggen weinig over de echte koppeling. De eerste kijkt er niet naar. De tweede meet iets anders dan componenten, zoals de volgende paragraaf laat zien.

## De oorzaak: een map is in Nextcloud geen component

Het beoordelingsinstrument gaat ervan uit dat de mappenstructuur de componenten laat zien. De richtlijn van SIG voor producenten (sectie 2.2) zegt dat componenten zichtbaar zijn in de broncode, "typically through the directory structure or through naming conventions". Bij veel systemen klopt dat: één map per functie.

Een Nextcloud-app is anders opgebouwd. Nextcloud bepaalt de map van een klasse aan de hand van wat voor soort klasse het is, niet van wat ze doet:

- Een route in `appinfo/routes.php` noemt `setup#status`. Nextcloud zoekt daarbij `OCA\OpenRegister\Controller\SetupController`. Elke controller staat dus in `lib/Controller/`.
- Entiteiten en hun mappers staan in `lib/Db/`.
- `appinfo/info.xml` registreert 56 achtergrondtaken en 41 consolecommando's op klassenaam.
- Composer koppelt de namespace `OCA\OpenRegister\` aan `lib/`, dus de namespace volgt de map.

Eén functie ligt daardoor verspreid over meerdere mappen. Neem flows: de controller staat in `lib/Controller/FlowController.php`, de logica in `lib/Service/Flow/` en de opslag in `lib/Db/`. Wie elke map als component telt, ziet bij elke aanroep van een controller naar zijn eigen service een lijn tussen twee componenten. Dat is geen verstrengeling. Zo hoort de laag eronder aangeroepen te worden.

## Het patroon: gelaagdheid per rol

De indeling van Nextcloud is een gelaagde architectuur, waarbij de mappen per laag zijn ingedeeld en niet per functie. Elke laag heeft een bekende naam:

| Laag | Map | Patroon | Bron |
|---|---|---|---|
| Ingang | `lib/Controller/` | Controller | Nextcloud: "Controllers are defined inside the lib/Controller/ directory." ([Controllers](https://docs.nextcloud.com/server/latest/developer_manual/basics/controllers.html)) |
| Logica | `lib/Service/` | Service Layer | Fowler: "Defines an application's boundary with a layer of services ..." ([Service Layer](https://martinfowler.com/eaaCatalog/serviceLayer.html)) |
| Opslag | `lib/Db/` | Data Mapper, entiteit en repository | Nextcloud: "create an Entity and a Repository class for it", met als voorbeeld `lib/Db/AuthorMapper.php` ([Database access](https://docs.nextcloud.com/server/latest/developer_manual/basics/storage/database.html)); Fowler: [Data Mapper](https://martinfowler.com/eaaCatalog/dataMapper.html) |

De functionele component zit in de naam, niet in de map. `FlowController`, `FlowService` en `FlowMapper` horen bij elkaar. Dat is de naamgevingsconventie die de richtlijn als tweede herkenningsteken noemt.

Martin Fowler beschrijft precies deze situatie in [Presentation Domain Data Layering](https://martinfowler.com/bliki/PresentationDomainDataLayering.html). Bij een grotere applicatie is het volgens hem "usually not best to use presentation-domain-data as the higher level of modules". Zijn advies: "split your top level into domain oriented modules which are internally layered."

Dat is de indeling die we hieronder meten. De componenten zijn functionele gebieden, en elk gebied is van binnen gelaagd. De mappen blijven staan zoals Nextcloud ze voorschrijft.

## De meetmethode: componenten die over mappen heen lopen

Een component is in deze methode een verzameling bestanden die bij één functie horen, in welke map ze ook staan. Het bestand `sigrid.components.yaml` legt tien gebieden vast als bestandspatronen:

| Gebied | Wat erin zit |
|---|---|
| Kernel | Contracten, waardeobjecten, de kernentiteiten `ObjectEntity`, `Schema` en `Register`, gedeelde events en exceptions |
| Core | Objecten, schema's, registers, relaties, historie, toegangsregels, organisaties, delen, `MagicMapper` |
| Processes | Flows, BPMN en DMN, regels, berekeningen, taken, zaken, formulieren, levenscyclus |
| Configuration | Configuratiepakketten, import en export, voorbeeldsets, instellingen, reparatiestappen |
| Compliance | Archivering, e-Depot, bewaartermijnen, vernietiging, AVG, audittrail, anonimisering |
| Integrations | MCP, app-hosting, GraphQL- en API-clients, agenda, contacten, DAV, federatie, webhooks |
| Files | Bestandsopslag, tekstextractie |
| Search | Zoeken, facetten, aggregaties, vectorisatie, weergaven |
| Notifications | Meldingen, web push, activiteit |
| Platform | Opstarten, middleware, capabilities, dashboard, instellingenpagina's |

Het gebied Processes pakt zo de bestanden van flows samen uit drie mappen: `FlowController` in een patroon op `lib/Controller/`, alles onder `lib/Service/Flow/` en de opslag onder `lib/Db/FlowRun/`. Het beoordelingsinstrument kent dit formaat: een `components:`-blok met per component een lijst `include`-patronen ([scope-configuratie](https://docs.sigrid-says.com/reference/analysis-scope-configuration.html)).

`scripts/component-patterns.py` maakt het bestand aan uit `scripts/component-map.csv`, waarin elk bestand een gebied heeft. `tests/Unit/Architecture/ComponentDefinitionTest.php` controleert dat elk PHP-bestand onder `lib/` in precies één gebied valt.

Binnen elk gebied geldt de gelaagdheid. Een ingang mag een service, de opslag en gedeelde typen gebruiken. Een service mag de opslag en gedeelde typen gebruiken. De opslag mag alleen gedeelde typen gebruiken. [ADR-013](https://github.com/ConductionNL/openregister/blob/main/openspec/architecture/adr-013-component-dependency-rules.md) legt die regels vast.

## Het meetinstrument

We meten met twee hulpmiddelen die elkaar controleren.

### Het script

Het script is [`tools/sig/analyse_dependencies.py`](https://github.com/ConductionNL/openregister/blob/main/tools/sig/analyse_dependencies.py). Het heeft alleen Python 3 nodig. Het leest de afhankelijkheden en telt de regels code zoals [`tools/sig/check_sig_maintainability.py`](https://github.com/ConductionNL/openregister/blob/main/tools/sig/check_sig_maintainability.py) dat doet, het schattingsinstrument dat onze kwaliteitscontrole draait.

Zo werkt het:

1. **Bestanden.** Elk PHP-bestand onder `lib/` is een knoop. `lib/Migration/` blijft buiten beschouwing, net als bij de beoordeling.
2. **Verwijzingen.** Bestand A hangt af van bestand B als A de klasse van B noemt: in een `use`-regel, met de volledige naam, of met de korte naam in dezelfde namespace. Commentaar telt niet mee. Elk paar bestanden telt één keer.
3. **Componenten.** Het script deelt de bestanden twee keer in: per map onder `lib/`, en per functioneel gebied uit `sigrid.components.yaml`.
4. **Maten.** Per indeling berekent het de verborgen code, de lijnen tussen componenten en de schendingsgraad, volgens de gepubliceerde beschrijving van beide maten.
5. **Lagen.** Elk bestand krijgt een rol uit de eerste map met een laagnaam: ingang, service, opslag of gedeeld type. Elke verwijzing gaat dan omlaag, blijft op hetzelfde niveau, of gaat omhoog.
6. **Kringen.** Het script zoekt de groepen bestanden die elkaar in een kring aanroepen, met het algoritme van Tarjan. Van de grootste groep geeft het de bestanden en de verwijzingen omhoog.

Met `--rev` meet het een oudere commit. Het pakt dan `lib/` van die commit uit met `git archive`. Een bestand dat de huidige indeling niet meer kent, krijgt het gebied van de meeste bestanden in zijn map.

Het script leest tekst met reguliere expressies en is geen PHP-parser. De beoordeling kan een enkele verwijzing anders plaatsen. De getallen zijn een herhaalbare lezing van de code, geen officiële uitslag. [`tools/sig/test_analyse_dependencies.py`](https://github.com/ConductionNL/openregister/blob/main/tools/sig/test_analyse_dependencies.py) test het script op een kleine `lib/` waarin je elke telling met de hand kunt narekenen.

### deptrac als tweede lezing

[deptrac](https://github.com/deptrac/deptrac) is een open source tool die afhankelijkheidsregels in PHP-code controleert, met een echte PHP-parser. Wij gebruiken versie 4.7 met twee regelsets:

- [`tools/sig/deptrac/layers.yaml`](https://github.com/ConductionNL/openregister/blob/main/tools/sig/deptrac/layers.yaml): de vier lagen, met dezelfde rolregel als het script.
- [`tools/sig/deptrac/areas.yaml`](https://github.com/ConductionNL/openregister/blob/main/tools/sig/deptrac/areas.yaml): de tien gebieden, gemaakt uit `sigrid.components.yaml` door `tools/sig/deptrac_areas.py`. Elk gebied mag Kernel gebruiken. Elke andere verwijzing tussen gebieden wordt gemeld.

deptrac telt op twee punten anders. Het meldt elke regel die een verboden klasse noemt, niet één melding per paar bestanden. En het geeft een klasse ook de afhankelijkheden van haar ouderklassen en traits. [`tools/sig/deptrac_summary.py`](https://github.com/ConductionNL/openregister/blob/main/tools/sig/deptrac_summary.py) telt daarom ook de verschillende klassenparen, zodat je beide tools kunt vergelijken.

## De uitkomsten

De metingen zijn van commit `70e5570455`, main op 9 november 2026: 5.387 bestanden en 394.863 regels code.

### De lagen houden

| Richting van de verwijzing | Aantal | Aandeel |
|---|---|---|
| Omlaag | 6.401 | 39,9% |
| Op hetzelfde niveau | 9.389 | 58,5% |
| Omhoog | 249 | 1,6% |

98,4% van de verwijzingen volgt de gelaagdheid. De 249 verwijzingen omhoog:

| Verwijzing omhoog | Script: bestandsparen | deptrac: klassenparen | Wat het is |
|---|---|---|---|
| Opslag naar service | 153 | 209 | Vooral `MagicMapper` die toegangsregels, objectbeleid en querycontroles in `lib/Service/` aanroept |
| Gedeeld type naar opslag | 54 | 93 | Events en contracten die een entiteit dragen, zoals `ObjectEntity` of `Schema` |
| Service naar ingang | 23 | 23 | Services die een achtergrondtaak op klassenaam inplannen |
| Gedeeld type naar service | 17 | 17 | Hulpbundels in `Support/` die een service-interface noemen |
| Opslag naar ingang | 1 | 1 | `MagicMapper` die `JobHandles` noemt |
| Gedeeld type naar ingang | 1 | 1 | `ObjectEventSubscription` die een listener-interface noemt |

deptrac vindt meer klassenparen door overerving en traits. `DraftUpdatedEvent` breidt `ObjectUpdatedEvent` uit en noemt zelf niets. deptrac meldt er toch `ObjectEntity` voor. De soorten verwijzing en hun volgorde zijn in beide tools gelijk.

### De functionele gebieden laten de echte koppeling zien

| Maat | Per map onder `lib/` | Per functioneel gebied |
|---|---|---|
| Componenten | 38 | 10 |
| Grootste component | `lib/Service`, 54,4% van de code | Core, 33,3% van de code |
| Verborgen code | 88,7% | 64,5% |
| Lijnen tussen componenten | 194 | 81 |
| Schendingsgraad | 0,065 | 0,194 |

De indeling per map oogt gunstiger, om één reden: `lib/Service` bevat meer dan de helft van de code. Bijna alle aanroepen tussen services blijven daardoor binnen één component, ook als ze tussen twee functies lopen.

De indeling per gebied telt die aanroepen wel. Dan blijkt dat de gebieden elkaars concrete klassen en entiteiten direct gebruiken. De meeste paren verwijzen in beide richtingen naar elkaar:

| Paar | Verwijzingen heen / terug | Meest gebruikt in de lichtere richting |
|---|---|---|
| Core / Processes | 250 / 234 | `Db/TypedFieldsJsonTrait`, `Db/AuditTrailMapperInterface`, `Db/MagicMapperInterface` |
| Core / Integrations | 189 / 313 | `Db/AccessLink`, `Db/Source`, `Service/LinkTarget` |
| Compliance / Core | 193 / 156 | `Db/AuditTrail`, `Service/AuthorizationAuditService`, `Service/Audit/PurposeGuardInterface` |
| Configuration / Core | 534 / 108 | `Service/ConfigurationServiceInterface`, `Service/LanguageService`, `Service/Export/ExportGateInterface` |
| Core / Kernel | 1.575 / 80 | `Event/AbstractObjectEntityEvent`, `Event/AbstractSchemaEvent`, `Event/AbstractPersonLinkEvent` |

deptrac telt op de gebieden 8.310 meldingen, verdeeld over 3.925 klassenparen.

### De koppeling zit op één plek

91 groepen bestanden roepen elkaar in een kring aan, samen 434 bestanden. De meeste groepen hebben twee tot vijf bestanden. Eén groep is veel groter: 190 bestanden en 23.989 regels code, 6,1% van `lib/`.

Die groep bevat `lib/Db/MagicMapper/` (54 bestanden), `lib/Service/Rbac/` (27), de object-events (16), `lib/Service/Object/` (14), de achtergrondtaken voor objecten (12) en `lib/Service/Lifecycle/` (11). `MagicMapper.php` heeft alleen al 50 verwijzingen binnen de groep. 49 verwijzingen in de groep gaan een laag omhoog, en die sluiten de kring: de opslag die toegangsregels, objectbeleid, events en taken aanroept.

De meeste verwijzingen omhoog en de grootste kring hebben dus één bron: de objectopslag en de toegangscontroles daarop.

### Latere commits

| Commit | Bestanden | Regels code | Per map: verborgen / schendingsgraad | Per gebied: verborgen / schendingsgraad | Omhoog | Grootste kring |
|---|---|---|---|---|---|---|
| `70e5570455` (9 nov) | 5.387 | 394.863 | 88,7% / 0,065 | 64,5% / 0,194 | 249 (1,6%) | 190 bestanden, 6,1% |
| `d20a7aee8c` (10 nov) | 6.846 | 414.315 | 89,4% / 0,051 | 64,9% / 0,188 | 255 (1,3%) | 230 bestanden, 5,9% |
| `29890847e7` (17 nov) | 7.482 | 458.606 | 85,2% / 0,055 | 62,5% / 0,194 | 302 (1,4%) | 280 bestanden, 6,7% |

De huidige indeling kent een paar bestanden van de oudere commits niet meer. Het script deelde die in op hun map: 4 op 9 november, 7 op 10 november.

## Wat dit betekent voor de beoordeling

Eerst iets over onszelf. De audit van SIG is de eerste keer dat we de koppeling binnen de backend op deze manier hebben doorgemeten. Onze eigen controles keken naar de lagen (ADR-013), niet naar de functionele gebieden. Deze pagina is dus ook voor ons een les: de lagen houden, en de gebieden zitten nog aan elkaar vast.

### De schatting per indeling

SIG publiceert alleen de grenzen voor 4 sterren. Ons scoreprogramma schat de rest, op een schaal van 0,5 tot 5,5. De getallen zijn dus een schatting, geen uitslag van SIG. Gemeten op main van 18 november (`d356db5b71`):

| Wat telt als component | Onafhankelijkheid | Verstrengeling | Modulariteit | Totaal |
|---|---|---|---|---|
| A. `lib/` als geheel (onze opgave, ADR-014) | 5,5 | 5,5 | 5,35 | 5,12 |
| B. Elke map onder `lib/` | 2,15 | 4,02 | 3,74 | 4,37 tot 4,47 |
| C. De tien functionele gebieden | 1,30 | 2,21 | 2,85 | 3,96 tot 4,20 |
| D. Gebieden, met objectopslag en toegang als gedeeld fundament | 1,31 | 2,01 | 2,79 | 3,93 tot 4,19 |
| E. Als D, maar het fundament wijst niet terug naar de gebieden | 1,37 | 3,45 | 3,29 | 4,12 tot 4,30 |

Variant A zegt niets over de koppeling: met één component is er niets om tussen te meten. Variant C is de eerlijke meting volgens het patroon van Fowler. Variant E is een simulatie, geen bestaande code.

### Een gewenste afhankelijkheid kost niets

Alle gebieden gebruiken de objectopslag en de toegangscontroles. Dat is zo bedoeld: het is kritische code die we niet dubbel willen hebben. De verstrengeling telt zo'n afhankelijkheid ook niet als fout. Een verbinding is alleen een fout als er ook een terug loopt, als hij een kring sluit, of als hij een pad herhaalt dat er al is.

Daarom hebben we in variant D de objectopslag en de toegangscontroles (het gebied Core) samengevoegd met Kernel tot één fundament. De verstrengeling wordt daar slechter van, niet beter. Het fundament verwijst zelf terug naar alle acht andere gebieden, in 1.210 verwijzingen tussen bestanden:

| Fundament verwijst naar | Verwijzingen | Voorbeelden |
|---|---|---|
| Processes | 334 | `Db/Task`, `BulkAction/BulkActionInterface` |
| Integrations | 245 | `Db/Source`, `Db/FederatedShare`, `Db/AccessLink` |
| Compliance | 244 | `Db/AuditTrail` en de audit-events |
| Configuration | 150 | `Db/Configuration`, `Service/LanguageService` |
| Search | 92 | `Db/RegisterSchema`, `Search/Db/ViewMapperInterface` |
| Files | 60 | `Files/Db/FileMapperInterface` |
| Platform | 58 | `AppInfo/Application` |
| Notifications | 27 | `Notifications/Db/WatcherMapperInterface` |

Elk van die terugverwijzingen maakt van een gewenste afhankelijkheid een verbinding die heen en terug loopt. Zonder die terugverwijzingen (variant E) stijgt de verstrengeling van 2,01 naar 3,45. Wat dan nog fout telt, zijn kringen tussen de andere gebieden onderling, zoals Compliance en Processes.

### Onafhankelijkheid telt elk gedeeld bestand

De onafhankelijkheid blijft in elke functionele indeling rond 1,3. Deze maat telt elk bestand waar een ander gebied naar verwijst als zichtbaar, ook als dat zo bedoeld is. In variant C is 37,5% van de code zichtbaar:

| Soort bestand | Bestanden | Regels code | Aandeel van de backend |
|---|---|---|---|
| Concrete klasse | 1.479 | 141.213 | 30,8% |
| Mapper | 90 | 8.960 | 2,0% |
| Interface | 821 | 8.723 | 1,9% |
| Entiteit | 68 | 5.797 | 1,3% |
| Exception | 141 | 4.627 | 1,0% |
| Event | 106 | 1.772 | 0,4% |
| Trait | 30 | 703 | 0,2% |

Het script deelt de bestanden in op hun declaratie en naam. Een entiteit die niet direct `Entity` uitbreidt, telt daardoor als concrete klasse. Het grootste deel zijn concrete klassen die andere gebieden direct gebruiken, zonder een smalle interface ertussen.

### Conclusie

De koppeling naar de objectopslag en de toegangscontroles is gewenst, en die kost in deze meting niets. Wat wel kost, zijn twee dingen:

- de objectopslag verwijst terug naar entiteiten en interfaces van andere gebieden;
- gebieden gebruiken elkaars concrete klassen in plaats van een smalle interface.

Beide zijn echte bevindingen over de code. Ze komen niet voort uit de manier van meten.

### Wat een hogere score vraagt

In ons scoreprogramma hoort bij elk niveau een grens voor de zichtbare code en voor de schendingsgraad. Voor de tien functionele gebieden, op een backend van 458.590 regels:

| Niveau | Zichtbare code | Schendingsgraad |
|---|---|---|
| Vandaag | 37,5% (171.795 regels) | 0,194 |
| 4,0 | hoogstens 4,1% (ongeveer 18.900 regels) | hoogstens 0,056 |
| 4,5 | hoogstens 2,75% (ongeveer 12.600 regels) | hoogstens 0,0375 |
| 5,0 | hoogstens 1,4% (ongeveer 6.300 regels) | hoogstens 0,019 |
| 5,5 | niets | 0 |

5,5 is de bovenkant van de schaal. Daar is geen enkel bestand zichtbaar voor een ander component en telt geen enkele verbinding als fout. Dat kan alleen als de backend één component is, en die indeling meet niets. 5,5 is dus geen zinvol doel.

Per gebied is vandaag zichtbaar:

| Gebied | Regels code | Zichtbaar voor andere gebieden |
|---|---|---|
| Core | 154.967 | 67.182 |
| Processes | 84.862 | 38.513 |
| Integrations | 55.846 | 20.258 |
| Compliance | 34.940 | 17.832 |
| Configuration | 56.686 | 10.651 |
| Kernel | 9.059 | 8.079 |
| Files | 18.732 | 3.741 |
| Search | 15.131 | 2.286 |
| Notifications | 19.855 | 1.734 |
| Platform | 8.512 | 1.519 |

Kernel is bedoeld als gedeelde basis en is dus bijna helemaal zichtbaar: 8.079 regels. Voor 4,5 blijft er dan ongeveer 4.500 regels over voor de publieke toegang van alle andere gebieden samen. Een interface per klasse past daar niet in: alleen de interfaces van vandaag zijn al 8.723 regels.

### Wat dat vraagt van de code

Binnen het patroon van Fowler komt het neer op vier veranderingen:

1. **Eén smalle toegang per gebied.** Andere gebieden gebruiken een paar interfaces van een gebied, niet zijn concrete klassen. Bij Fowler is de Service Layer precies die grens van een module.
2. **Entiteiten gaan niet over de grens.** Een gebied geeft een id of een eenvoudig dataobject door. Een entiteit die echt gedeeld is, hoort in het fundament.
3. **Het fundament wijst nooit terug.** Het fundament definieert events en interfaces. De gebieden haken daarop in via de event dispatcher en de registratie in de container van Nextcloud.
4. **Gebieden verwijzen in één richting.** Twee gebieden verwijzen niet allebei naar elkaar. De andere richting loopt via een event.

Punt 3 en 4 bepalen de verstrengeling. Punt 1 en 2 bepalen de onafhankelijkheid, en daar zit het meeste werk.

### Is deze manier van meten gerechtvaardigd?

Grotendeels wel, als de componenten functioneel zijn:

- **De principes zijn degelijk.** Onafhankelijkheid meet information hiding (Parnas, 1972). Verstrengeling meet of afhankelijkheden een kring vormen, wat het acyclic dependencies principle van Robert C. Martin verbiedt. Ook het advies van Fowler vraagt om smalle grenzen tussen modules.
- **De bevindingen zijn echt.** Het fundament wijst terug, en gebieden gebruiken elkaars concrete klassen. Dat zien we ook zonder sterren.
- **Elke map als component is voor Nextcloud niet gerechtvaardigd.** Die indeling meet de lagen, niet de componenten.

Er zijn drie kanttekeningen:

- **Een bedoeld gedeeld bestand telt even zwaar als een ongewenst gedeeld bestand.** Ook een interface telt als zichtbaar. Volgens het stable dependencies principle van Martin is afhankelijk zijn van een stabiel fundament juist goed.
- **Het is alles of niets per bestand.** Eén verwijzing van buiten maakt het hele bestand zichtbaar.
- **Het blijft een schatting.** SIG publiceert alleen de grenzen voor 4 sterren, niet de omrekening daaromheen.

Ons doel is daarom 4 in de functionele indeling, en daarna 4,5. Niet 5,5.

## Wat het onderhoud kost

Een sterrenbeoordeling zegt hoe moeilijk de code te veranderen is. Wie OpenRegister gebruikt, betaalt voor iets anders: het onderhoud. Daarom kijken we ook naar wat het onderhoud werkelijk kost.

| | Per jaar |
|---|---|
| Ontwikkeling en techniek | 2,5 fte |
| Inzet vanuit de overheid | 0,5 fte |
| Totaal | 3 fte |

De herbouwwaarde van OpenRegister schatten we zelf op 60 persoonsjaren. Dat is onze eigen schatting; SIG berekent een eigen herbouwwaarde uit het volume van de code.

3 fte per jaar is 5% van die herbouwwaarde. Eén fte houdt dus 20 persoonsjaren aan herbouwwaarde draaiende.

Bij dezelfde code bepaalt de beheerpartij hoeveel mensen dat kost. Bij ons hangt die efficiëntie van twee dingen af:

- **De kwaliteit van de code.** Wat deze pagina meet: hoe los de gebieden van elkaar staan, en hoeveel een wijziging raakt.
- **De code factory.** Agents bouwen de code, met een spec, een ADR en een scherm vooraf, en gates en tests achteraf. De ontwikkelaar beslist en merget. [De code factory](https://docs.conduction.nl/WayOfWork/spec-driven-development) beschrijft hoe dat werkt.

Daarbovenop telt hoeveel codebases er zijn en hoeveel partijen de kosten delen. OpenRegister is één kern voor veel registers, die meerdere organisaties samen gebruiken. [Three ways to look at maintainability](https://docs.conduction.nl/WayOfWork/development-pipeline#three-ways-to-look-at-maintainability) werkt die afweging uit.

Het plan op deze pagina verbetert de eerste factor. Elke stap maakt een wijziging kleiner, en dus het onderhoud goedkoper.

## Zelf nameten

Vanuit de hoofdmap van de repository:

```bash
# Het script: alleen Python 3 nodig.
python3 tools/sig/analyse_dependencies.py --rev 70e5570455 --out /tmp/analyse-70e5570455

# deptrac: eenmalig installeren, dan beide regelsets draaien.
composer --working-dir=vendor-bin/deptrac install
tools/sig/deptrac.sh layers --rev 70e5570455 --out /tmp/analyse-70e5570455
tools/sig/deptrac.sh areas --rev 70e5570455 --out /tmp/analyse-70e5570455

# De schatting per indeling: eerst het volledige rapport, dan de vijf varianten.
python3 tools/sig/check_sig_maintainability.py report . --json /tmp/rapport.json
python3 tools/sig/rate_component_views.py --report /tmp/rapport.json --json /tmp/varianten.json

# De tests van de scripts.
python3 -m unittest discover -s tools/sig -p 'test_*.py'
```

Zonder `--rev` meten beide tools de werkmap. Het script doet er ongeveer 15 seconden over, elke deptrac-run minder dan een minuut. Het volledige rapport duurt ongeveer drie minuten, [`tools/sig/rate_component_views.py`](https://github.com/ConductionNL/openregister/blob/main/tools/sig/rate_component_views.py) daarna een paar seconden.

## Resultaten

Per commit staat de uitvoer van beide tools in een eigen map:

- `report.md`: de tabellen van deze pagina
- `results.json`: alle getallen, met de volledige lijsten van verwijzingen omhoog en van de bestanden in de kring
- `areas.dot`: de graaf van de gebieden voor Graphviz, met de paren die in beide richtingen verwijzen in rood
- `deptrac-layers.json`, `deptrac-areas.json`: de tellingen van deptrac per paar lagen of gebieden

| Commit | Resultaten |
|---|---|
| `70e5570455`, main op 9 november 2026 | [rapport](./dependency-analysis/70e5570455/report.md) |
| `d20a7aee8c`, main op 10 november 2026 | [rapport](./dependency-analysis/d20a7aee8c/report.md) |
| `29890847e7`, main op 17 november 2026 | [rapport](./dependency-analysis/29890847e7/report.md) |
| `d356db5b71`, main op 18 november 2026 | [schatting per indeling](https://github.com/ConductionNL/openregister/blob/main/docs/sig/dependency-analysis/d356db5b71/rating-views.json) |

## Zie ook

- [Vragenlijst onderhoudbaarheid](./maintainability.md), vraag 16: de componenten en hoe ze samenhangen
- [ARCHITECTURE.md](https://github.com/ConductionNL/openregister/blob/main/ARCHITECTURE.md): de beschrijving van de componenten in de repository
- [ADR-012](https://github.com/ConductionNL/openregister/blob/main/openspec/architecture/adr-012-functional-components-over-the-nextcloud-layout.md): de functionele gebieden
- [ADR-013](https://github.com/ConductionNL/openregister/blob/main/openspec/architecture/adr-013-component-dependency-rules.md): de regels voor de lagen
- [ADR-014](https://github.com/ConductionNL/openregister/blob/main/openspec/architecture/adr-014-sig-components-are-top-level-directories.md): de mappen in de hoofdmap als componenten
