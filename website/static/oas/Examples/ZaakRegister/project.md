# Plan Zaakregister

We documenteren het plan hier, in de open register repository, we documenteren HIER onder, we houden eventueel extra documenten bij in een map source in DEZE MAP

We doen de registers in princiepe niet één voor een maar parallel.

## Epics per register (as of now)
- [ZTC](https://conduction.atlassian.net/browse/ZAAKREG-54) (Barry) 
- [ZRC](https://conduction.atlassian.net/browse/ZAAKREG-56) 
- [DRC](https://conduction.atlassian.net/browse/ZAAKREG-55) (Robert)
- [BRC](https://conduction.atlassian.net/browse/ZAAKREG-57) (Wilco)


## Per register moeten we
- Registers (in open register)
- Endpoints -> we maken één issue met checkbox per endpoint
- Rules per endpoint -> we maken één issue met checkbox per endpoint (kan worden gekopierd van endpoints)
- Mappings -> Maken een issue met een checkbox per maping
- Testen -> op te delen in test stappen a.h.v. scripts en cassusen

(voor al deze dingen moeten dus losse issues komen)

## Overzicht van ZGW oas (bouw informatie)
- [ZTC](https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/) Catalogi
- [ZRC](https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/) Zaken
- [DRC](https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/) Documenten
- [BRC](https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/) Besluiten

Nu niet in scope
- [Referentie en selectie lijsten](https://redocly.github.io/redoc/?url=https://raw.githubusercontent.com/VNG-Realisatie/VNG-referentielijsten/master/src/openapi.yaml&nocors)
- [Autorisatie](https://vng-realisatie.github.io/gemma-zaken/standaard/autorisaties/)
- [Notificatie](https://vng-realisatie.github.io/gemma-zaken/standaard/notificaties/)
- [Notificatie consumers](https://vng-realisatie.github.io/gemma-zaken/standaard/notificaties-consumer/)


##Wat we weten
- De php code hebben we 
- Gaat om configuratie

Algemene stappen
- [Overkoepelende techniek](https://conduction.atlassian.net/browse/ZAAKREG-58)
- - Notificaties 

## Testen
- Testscript RRoxit
- Testscript van API testvoorziening
- OPhalen welke fitlers er worden gebruikt op de mijn omgeving (Remko D)

(zijn bijde ingeladen in postman in de workspace ZGW)

Hebben we nog?
- Mappings nog? (versamelen in een open register repro) / example mappen daar maken we een mapje zaakregister en daarin doen we alles wat we nodig hebben. 
- Alles nu even in de open Registers github repository

## PLanning
Kleine dag tot dag planning zodat we een beetje weten waar we naar toe werken

### 22-5
- Endpoints (per register zie boven)
- Authenticie rule (per method) (Rule)

Middag update

Robert
- DRC heeft 3 van de 5 object type van alle endpoints
- 2 Objecttypen wijken
- Autoriastie rule nog niet gedaan
Barry
- ZTC heeft alle endpoints
- ZRC door chat GPT laten doen
Wilco
- BRC van endpoints voorzien


### 23-5
- Eerste endpoint test (@matthias) op ZTC en DRC
- Rules ZTC /DRC

### 26-5
- Eerste rules test (@matthias) op ZTC en DRC
- Verdere Rules ZTC /DRC

### 27-5
- Finale  rules test (@matthias) op ZTC en DRC
- Rules ZTR  /BRC
- Uitleveren ZTC en DRC voor extern testen(mits geen bevindingen meer op interne tests)

### 28-5
- Eerste rules test (@matthias) op ZRC /BRC
- Verdere Rules ZRC /BRC

### 30-5 
- Finale  rules test (@matthias) op ZRC / BRC
- Uitleveren ZRC /BRC voor extern testen (mits geen bevindingen meer op interne tests)

### 02-6
- Start xxlnc inteegratie (nog geen indicatie of inschatting)
- Notificatie (nog geen indicatie of inschatting)


## Parkeren / Obeservateis
- Rules zijn per methods dat leid tot duplicaite, we willen graag meerede methods per rule of geen method
- Willen we geen standaard CRUD endpoints?