# Kwaliteitsrapport OpenRegister

## Overzicht

Dit document geeft een overzicht van de gevonden issues door PHPCS, PHPMD en Psalm.

## PHPCS (PHP CodeSniffer)

### Totaal
- **Errors**: 115
- **Warnings**: 2
- **Fixable**: De meeste errors kunnen automatisch gefixed worden met `composer cs:fix`

### Error types (gesorteerd op frequentie)

| Type | Aantal | Beschrijving |
|------|--------|--------------|
| No blank line found after control structure | 60 | Ontbrekende lege regel na control structure |
| End comment for long condition not found | 21 | Ontbrekende end comment voor lange if/else |
| Tag value indented incorrectly (@SuppressWarnings) | 8 | Verkeerde indentatie van @SuppressWarnings tag |
| Equals sign not aligned | 7 | Gelijkheidstekens niet uitgelijnd |
| Blank line found after control structure | 3 | Onverwachte lege regel na control structure |
| Tag value indented incorrectly (@psalm-suppress) | 2 | Verkeerde indentatie van @psalm-suppress tag |
| Tag value indented incorrectly (@psalm-return) | 2 | Verkeerde indentatie van @psalm-return tag |
| Tag value indented incorrectly (@SuppressWarnings UnusedPrivateMethod) | 2 | Verkeerde indentatie van @SuppressWarnings tag |
| No blank line following inline comment | 1 | Ontbrekende lege regel na inline comment |
| Expected space before "?" | 1 | Ontbrekende spatie voor ternary operator |
| Expected space before ":" | 1 | Ontbrekende spatie voor ternary operator |
| Equals sign alignment | 1 | Gelijkheidsteken niet correct uitgelijnd |

**Totaal**: 115 errors, waarvan de meeste automatisch fixbaar zijn met `composer cs:fix`

## PHPMD (PHP Mess Detector)

### Totaal
- **Violations**: 1.655

### Top 20 violation types

| Type | Aantal | Beschrijving |
|------|--------|--------------|
| CyclomaticComplexity | 284 | Methoden met te hoge cyclomatische complexiteit (>10) |
| BooleanArgumentFlag | 263 | Methoden met boolean flags (SRP violation) |
| ElseExpression | 173 | Gebruik van else statements |
| ExcessiveMethodLength | 172 | Methoden langer dan 100 regels |
| NPathComplexity | 163 | Methoden met te hoge NPath complexiteit (>200) |
| UnusedFormalParameter | 161 | Ongebruikte parameters |
| LongVariable | 128 | Variabelen langer dan 20 karakters |
| ExcessiveClassComplexity | 74 | Klassen met te hoge complexiteit (>50) |
| StaticAccess | 60 | Statische method calls |
| CouplingBetweenObjects | 45 | Te veel dependencies (>13) |
| TooManyPublicMethods | 34 | Te veel publieke methoden (>10) |
| ExcessiveClassLength | 34 | Klassen langer dan 1000 regels |
| ExcessiveParameterList | 22 | Methoden met te veel parameters (>10) |
| TooManyFields | 15 | Te veel class properties |
| TooManyMethods | 12 | Te veel methoden (>25) |
| Superglobals | 8 | Gebruik van superglobals ($_SERVER, etc.) |
| BooleanGetMethodName | 4 | Boolean getters zonder 'is'/'has' prefix |
| ExcessivePublicCount | 3 | Te veel publieke items (>45) |

### Belangrijkste problemen
1. **ObjectService** klasse heeft:
   - 2617 regels code (threshold: 1000)
   - 54 publieke methoden/attributen (threshold: 45)
   - 50 non-getter/setter methoden (threshold: 25)
   - 40 publieke methoden (threshold: 10)
   - Complexity van 167 (threshold: 50)
   - Coupling van 58 (threshold: 13)
   - Constructor met 39 parameters (threshold: 10)

2. **TextExtractionService** klasse heeft:
   - 1830 regels code (threshold: 1000)
   - Complexity van 122 (threshold: 50)
   - Coupling van 24 (threshold: 13)
   - Constructor met 11 parameters (threshold: 10)

3. Veel methoden met boolean flags die SRP schenden
4. Veel else expressions die kunnen worden vereenvoudigd
5. Veel ongebruikte parameters

## Psalm

### Totaal
- **Errors**: 0
- **Other issues**: 1.171 (info/warnings)
- **Type coverage**: 88.28%
- **Auto-fixable**: 2 issues (MissingClosureReturnType)

### Status
Psalm vindt geen errors, alleen info/warnings. De meeste zijn waarschijnlijk type hints die ontbreken of kunnen worden verbeterd.

## Aanbevelingen

### Prioriteit 1 (Kritiek)
1. **Refactor ObjectService**: Deze klasse is veel te groot en complex. Overweeg:
   - Splitsen in meerdere services
   - Gebruik van strategy pattern voor verschillende operaties
   - Dependency injection verbeteren

2. **Refactor TextExtractionService**: Ook deze klasse is te groot. Overweeg:
   - Handler pattern voor verschillende extractie types
   - Splitsen in meerdere services

### Prioriteit 2 (Hoog)
1. **Fix PHPCS errors**: Run `composer cs:fix` om automatisch fixbare errors op te lossen
2. **Verminder boolean flags**: Vervang boolean parameters door dedicated methoden of value objects
3. **Verminder else expressions**: Refactor naar early returns waar mogelijk
4. **Verwijder ongebruikte parameters**: Clean up method signatures

### Prioriteit 3 (Medium)
1. **Verbeter type coverage**: Werk aan de 1171 Psalm issues om type coverage te verbeteren
2. **Verminder cyclomatische complexiteit**: Splits complexe methoden op
3. **Verminder method length**: Splits lange methoden op in kleinere, gerichte methoden
4. **Verminder coupling**: Gebruik dependency injection en interfaces

## Commando's

```bash
# PHPCS check
composer phpcs

# PHPCS auto-fix
composer cs:fix

# PHPMD check
composer phpmd

# Psalm check
composer psalm

# Psalm met info
composer psalm --show-info=true

# Volledig kwaliteitsrapport
composer phpqa
```

## Rapport genereren

Voor een volledig HTML rapport:
```bash
composer phpqa
# Open phpqa/phpqa-offline.html in browser
```

