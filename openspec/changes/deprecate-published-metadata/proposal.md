# Deprecate Published/Depublished Metadata

## Problem
Replace the dedicated `published`/`depublished` object metadata system in OpenRegister with RBAC conditional rules using the `$now` dynamic variable. The legacy system adds two datetime columns (`_published`, `_depublished`) to every magic table, requires specialized hydration logic in `SaveObject`, pollutes search and facet handlers, and conflates visibility control (an authorization concern) with publication lifecycle timestamps (a data concern).

## Proposed Solution
Replace the dedicated `published`/`depublished` object metadata system in OpenRegister with RBAC conditional rules using the `$now` dynamic variable. The legacy system adds two datetime columns (`_published`, `_depublished`) to every magic table, requires specialized hydration logic in `SaveObject`, pollutes search and facet handlers, and conflates visibility control (an authorization concern) with publication lifecycle timestamps (a data concern). The RBAC `$now` mechanism, already implemented 
