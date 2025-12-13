# PHPMD Analysis Report for OpenRegister

## Executive Summary

**Total Violations**: ~3,928 lines of output
**Analysis Date**: December 13, 2025

## Violation Summary by Type

| Rule | Count | Priority | Auto-fixable |
|------|-------|----------|--------------|
| ElseExpression | 841 | Medium | Partial |
| ShortVariable | 496 | Low | Manual |
| MissingImport | 477 | High | Yes |
| UnusedFormalParameter | 396 | Medium | Manual |
| CyclomaticComplexity | 321 | High | Manual (Refactor) |
| BooleanArgumentFlag | 225 | Medium | Manual (Refactor) |
| ExcessiveMethodLength | 200 | High | Manual (Refactor) |
| NPathComplexity | 178 | High | Manual (Refactor) |
| CamelCaseParameterName | 112 | Low | Yes |
| LongVariable | 107 | Low | Manual |
| UnusedPrivateMethod | 98 | Medium | Manual |
| StaticAccess | 64 | Low | Manual |
| UnusedLocalVariable | 55 | Medium | Yes |
| ExcessiveClassComplexity | 54 | High | Manual (Refactor) |
| UndefinedVariable | 48 | High | Manual |
| CouplingBetweenObjects | 37 | High | Manual (Refactor) |
| ExcessiveClassLength | 34 | High | Manual (Refactor) |
| TooManyPublicMethods | 25 | Medium | Manual (Refactor) |
| ExcessiveParameterList | 22 | Medium | Manual (Refactor) |
| CamelCaseVariableName | 16 | Low | Yes |
| Superglobals | 14 | High | Manual |
| UnusedPrivateField | 12 | Medium | Manual |
| TooManyFields | 12 | Medium | Manual (Refactor) |
| TooManyMethods | 10 | Medium | Manual (Refactor) |
| CountInLoopExpression | 10 | Medium | Yes |
| ErrorControlOperator | 5 | Medium | Manual |
| EmptyCatchBlock | 4 | High | Manual |
| BooleanGetMethodName | 4 | Low | Manual |
| Unexpected | 2 | Unknown | Manual |
| ExcessivePublicCount | 2 | Medium | Manual (Refactor) |
| ExitExpression | 1 | High | Manual |
| DuplicatedArrayKey | 1 | High | Manual |

## Top 20 Files with Most Violations

| File | Violations | Priority |
|------|------------|----------|
| lib/Service/GuzzleSolrService.php | 409 | Critical |
| lib/Db/ObjectEntityMapper.php | 214 | Critical |
| lib/Service/ObjectService.php | 194 | Critical |
| lib/Service/ObjectHandlers/SaveObject.php | 116 | High |
| lib/Service/ObjectHandlers/SaveObjects.php | 100 | High |
| lib/Service/ChatService.php | 88 | High |
| lib/Service/ImportService.php | 86 | High |
| lib/Service/SettingsService.php | 85 | High |
| lib/Db/SchemaMapper.php | 81 | High |
| lib/Controller/SettingsController.php | 76 | High |
| lib/Service/VectorEmbeddingService.php | 73 | High |
| lib/Service/ConfigurationService.php | 72 | High |
| lib/Service/FileService.php | 66 | Medium |
| lib/Service/ObjectHandlers/RenderObject.php | 60 | Medium |
| lib/Controller/ObjectsController.php | 50 | Medium |
| lib/Service/ObjectHandlers/ValidateObject.php | 46 | Medium |
| lib/Db/SearchTrailMapper.php | 45 | Medium |
| lib/Service/SolrFileService.php | 44 | Medium |
| lib/Service/MagicMapper.php | 44 | Medium |
| lib/Controller/RegistersController.php | 44 | Medium |

## Recommended Fix Strategy

### Phase 1: Quick Wins (High Impact, Low Effort)
1. **MissingImport (477)** - Add missing use statements
2. **CamelCaseParameterName (112)** - Rename parameters
3. **CamelCaseVariableName (16)** - Rename variables
4. **UnusedLocalVariable (55)** - Remove unused variables
5. **CountInLoopExpression (10)** - Extract count before loop

### Phase 2: Code Quality Improvements (Medium Impact, Medium Effort)
6. **ShortVariable (496)** - Rename short variables (e.g. $id -> $objectId)
7. **UnusedFormalParameter (396)** - Remove or comment unused parameters
8. **UnusedPrivateMethod (98)** - Remove unused methods
9. **UnusedPrivateField (12)** - Remove unused fields
10. **ErrorControlOperator (5)** - Replace @ with proper error handling
11. **EmptyCatchBlock (4)** - Add proper error handling

### Phase 3: Structural Improvements (High Impact, High Effort)
12. **ElseExpression (841)** - Refactor to early returns
13. **CyclomaticComplexity (321)** - Break down complex methods
14. **ExcessiveMethodLength (200)** - Extract methods
15. **ExcessiveClassLength (34)** - Consider splitting classes
16. **ExcessiveClassComplexity (54)** - Refactor complex classes
17. **CouplingBetweenObjects (37)** - Reduce dependencies

### Phase 4: Architecture Improvements (Low Priority)
18. **BooleanArgumentFlag (225)** - Split methods or use strategy pattern
19. **TooManyPublicMethods (25)** - Consider facades or split classes
20. **ExcessiveParameterList (22)** - Use parameter objects
21. **Superglobals (14)** - Use dependency injection
22. **LongVariable (107)** - Shorten overly long names
23. **StaticAccess (64)** - Consider dependency injection

## Critical Issues to Address First

1. **lib/Service/GuzzleSolrService.php** - 409 violations (needs major refactoring)
2. **lib/Db/ObjectEntityMapper.php** - 214 violations
3. **lib/Service/ObjectService.php** - 194 violations
4. **UndefinedVariable** - 48 instances (potential bugs)
5. **ExitExpression** - 1 instance (anti-pattern)
6. **DuplicatedArrayKey** - 1 instance (potential bug)

## Notes

- Some violations (like ElseExpression) are stylistic and low priority.
- Focus on violations that indicate actual bugs or maintenance issues.
- Large classes and methods need architectural review before refactoring.
- Consider creating baseline for existing violations and prevent new ones.

