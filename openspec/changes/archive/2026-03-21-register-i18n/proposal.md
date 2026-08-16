# Register Internationalization

## Problem
Implement multi-language content management for register objects so that translatable properties store per-language variants, APIs negotiate content language via Accept-Language headers, and the UI provides language-aware editing with completeness tracking. The system MUST support at minimum Dutch (NL, required) and English (EN, optional) to comply with Single Digital Gateway (SDG) Regulation (EU) 2018/1724 for cross-border EU service access, while the architecture MUST allow registers to configure any number of BCP 47 languages including RTL scripts. This spec covers data-level i18n for register object content -- it is distinct from the app UI string translations governed by `i18n-infrastructure`, `i18n-string-extraction`, `i18n-backend-messages`, and `i18n-dutch-translations` specs, which handle Nextcloud `IL10N` / `t()` / `$l->t()` for interface labels.
**Source**: Gap identified in cross-platform analysis; four competitors implement field-level i18n. SDG compliance requires English availability for cross-border services. ADR-005 mandates NL+EN as minimum languages for all Conduction apps.

## Proposed Solution
Implement Register Internationalization following the detailed specification. Key requirements include:
- Requirement: Schema properties MUST support a translatable flag
- Requirement: Objects MUST store translations per translatable property as language-keyed JSON
- Requirement: The API MUST support language negotiation via Accept-Language header
- Requirement: Fallback language chain MUST be configurable per register
- Requirement: Nextcloud IL10N integration MUST translate app UI independently from object content

## Scope
This change covers all requirements defined in the register-i18n specification.

## Success Criteria
- Define a translatable property
- Non-translatable property remains unaffected
- Mark multiple properties as translatable
- Translatable flag on nested object properties
- Translatable flag in schema UI editor
