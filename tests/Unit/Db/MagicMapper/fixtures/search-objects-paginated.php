<?php

/**
 * Recorded outcomes for MagicMapperSearchObjectsPaginatedTest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

return array (
  'no context' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'register only' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'schema only' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'single from @self' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'searchTable [{"@self":{"register":1,"schema":2},"_limit":5,"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      3 => 'countTable [{"x":1,"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      4 => 'getIgnoredFilters []',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'row-2',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
        0 => 'colour',
      ),
      'metrics' => 
      array (
        'search_ms' => '<ms>',
        'count_ms' => '<ms>',
      ),
    ),
  ),
  'single from underscore keys' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'searchTable [{"_register":"1","_schema":"2","_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      3 => 'countTable [{"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      4 => 'getIgnoredFilters []',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'row-2',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
        0 => 'colour',
      ),
      'metrics' => 
      array (
        'search_ms' => '<ms>',
        'count_ms' => '<ms>',
      ),
    ),
  ),
  'single from plain keys' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'searchTable [{"register":1,"schema":2,"_rbac":false,"_multitenancy":false},"register:1","schema:2"]',
      3 => 'countTable [{"_rbac":false,"_multitenancy":false},"register:1","schema:2"]',
      4 => 'getIgnoredFilters []',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'row-2',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
        0 => 'colour',
      ),
      'metrics' => 
      array (
        'search_ms' => '<ms>',
        'count_ms' => '<ms>',
      ),
    ),
  ),
  '@self wins over underscore' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'searchTable [{"@self":{"register":1,"schema":2},"_register":9,"_schema":9,"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      3 => 'countTable [{"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      4 => 'getIgnoredFilters []',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'row-2',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
        0 => 'colour',
      ),
      'metrics' => 
      array (
        'search_ms' => '<ms>',
        'count_ms' => '<ms>',
      ),
    ),
  ),
  'single register lookup fails' => 
  array (
    'calls' => 
    array (
      0 => 'register find [404,false,false]',
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] Failed to resolve register/schema {"error":"no register 404"}',
      1 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'single schema lookup fails' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [404,[],false,false]',
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] Failed to resolve register/schema {"error":"no schema 404"}',
      1 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'single lookup fails with search' => 
  array (
    'calls' => 
    array (
      0 => 'register find [404,false,false]',
      1 => 'findMultiple [["u1"],false,null]',
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] Failed to resolve register/schema {"error":"no register 404"}',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'schema list in @self' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'countTable [{"_rbac":true,"_multitenancy":true,"@self":{"schema":2}},"register:1","schema:2"]',
      3 => 'schema find [3,[],false,false]',
      4 => 'countTable [{"_rbac":true,"_multitenancy":true,"@self":{"schema":3}},"register:1","schema:3"]',
      5 => 'searchAcross [{"@self":{"schema":[2,3]},"register":1,"_rbac":true,"_multitenancy":true},[{"register":"register:1","schema":"schema:2"},{"register":"register:1","schema":"schema:3"}]]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'union-row',
      ),
      'total' => 25,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
        3 => 
        array (
          'id' => 3,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 3',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
      ),
      'source' => 'magic_mapper',
    ),
  ),
  'empty schema list' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [0,[],false,false]',
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] Failed to resolve register/schema {"error":"no schema 0"}',
      1 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'multi with registers' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'register find [5,false,false]',
      2 => 'schema find [2,[],false,false]',
      3 => 'countTable [{"_rbac":true,"_multitenancy":true,"@self":{"schema":2}},"register:1","schema:2"]',
      4 => 'schema find [3,[],false,false]',
      5 => 'countTable [{"_rbac":true,"_multitenancy":true,"@self":{"schema":3}},"register:1","schema:3"]',
      6 => 'searchAcross [{"_schemas":[2,3],"_registers":["1","5"],"_rbac":true,"_multitenancy":true},[{"register":"register:1","schema":"schema:2"},{"register":"register:1","schema":"schema:3"}]]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'union-row',
      ),
      'total' => 25,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
        5 => 
        array (
          'id' => 5,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 5',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
        3 => 
        array (
          'id' => 3,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 3',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
      ),
      'source' => 'magic_mapper',
    ),
  ),
  'multi with one register' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'countTable [{"_rbac":true,"_multitenancy":true,"@self":{"schema":2}},"register:1","schema:2"]',
      3 => 'searchAcross [{"@self":{"schemas":["2"]},"register":"1","_rbac":true,"_multitenancy":true},[{"register":"register:1","schema":"schema:2"}]]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'union-row',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
      ),
      'source' => 'magic_mapper',
    ),
  ),
  'multi registers win over register' => 
  array (
    'calls' => 
    array (
      0 => 'register find [5,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'countTable [{"_rbac":true,"_multitenancy":true,"@self":{"schema":2}},"register:5","schema:2"]',
      3 => 'searchAcross [{"_schemas":[2],"register":1,"_registers":[5],"_rbac":true,"_multitenancy":true},[{"register":"register:5","schema":"schema:2"}]]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'union-row',
      ),
      'total' => 12,
      'registers' => 
      array (
        5 => 
        array (
          'id' => 5,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 5',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
      ),
      'source' => 'magic_mapper',
    ),
  ),
  'multi empty registers list' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'countTable [{"_rbac":true,"_multitenancy":true,"@self":{"schema":2}},"register:1","schema:2"]',
      3 => 'searchAcross [{"_schemas":[2],"register":1,"_registers":[],"_rbac":true,"_multitenancy":true},[{"register":"register:1","schema":"schema:2"}]]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'union-row',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
      ),
      'source' => 'magic_mapper',
    ),
  ),
  'multi without registers' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] Failed to build schema->register map for multi-schema search {"error":"no database"}',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'multi schemas not a list' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'searchTable [{"_schemas":"2","register":1,"schema":2,"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      3 => 'countTable [{"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      4 => 'getIgnoredFilters []',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'row-2',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
        0 => 'colour',
      ),
      'metrics' => 
      array (
        'search_ms' => '<ms>',
        'count_ms' => '<ms>',
      ),
    ),
  ),
  'multi but a schema is named' => 
  array (
    'calls' => 
    array (
      0 => 'register find [1,false,false]',
      1 => 'schema find [2,[],false,false]',
      2 => 'searchTable [{"_schemas":[2,3],"schema":2,"register":1,"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      3 => 'countTable [{"_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
      4 => 'getIgnoredFilters []',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'row-2',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
        0 => 'colour',
      ),
      'metrics' => 
      array (
        'search_ms' => '<ms>',
        'count_ms' => '<ms>',
      ),
    ),
  ),
  'ids' => 
  array (
    'calls' => 
    array (
      0 => 'findMultiple [["u1","u2"],false,null]',
      1 => 'register find [1,false,false]',
      2 => 'schema find [2,[],false,false]',
      3 => 'register find [5,false,false]',
      4 => 'schema find [3,[],false,false]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'object:u1',
        1 => 'object:u2',
      ),
      'total' => 2,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
        5 => 
        array (
          'id' => 5,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 5',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
        3 => 
        array (
          'id' => 3,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 3',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
    ),
  ),
  'ids with failed single lookup' => 
  array (
    'calls' => 
    array (
      0 => 'register find [404,false,false]',
      1 => 'findMultiple [["u1"],false,null]',
      2 => 'register find [1,false,false]',
      3 => 'schema find [2,[],false,false]',
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] Failed to resolve register/schema {"error":"no register 404"}',
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'object:u1',
      ),
      'total' => 1,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
    ),
  ),
  'empty ids' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'relations' => 
  array (
    'calls' => 
    array (
      0 => 'findByRelation ["rel-1",false]',
      1 => 'register find [1,false,false]',
      2 => 'schema find [2,[],false,false]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'object:r1',
      ),
      'total' => 1,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
    ),
  ),
  'relations with a register' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'relations empty' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'relations not a string' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'text search' => 
  array (
    'calls' => 
    array (
      0 => 'getAllRegisterSchemaPairs []',
      1 => 'register find [1,false,false]',
      2 => 'schema find [2,[],false,false]',
      3 => 'register find [1,false,false]',
      4 => 'schema find [404,[],false,false]',
      5 => 'searchAcross [{"_search":"zaak","_limit":3,"_rbac":true,"_multitenancy":true},[{"register":"register:1","schema":"schema:2"}]]',
      6 => 'countTable [{"_search":"zaak","_rbac":true,"_multitenancy":true},"register:1","schema:2"]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 'union-row',
      ),
      'total' => 12,
      'registers' => 
      array (
        1 => 
        array (
          'id' => 1,
          'uuid' => NULL,
          'slug' => NULL,
          'title' => 'register 1',
          'version' => NULL,
          'description' => NULL,
          'schemas' => 
          array (
            0 => 2,
            1 => 3,
          ),
          'source' => NULL,
          'tablePrefix' => NULL,
          'folder' => NULL,
          'updated' => NULL,
          'created' => NULL,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'groups' => 
          array (
          ),
          'type' => NULL,
          'languages' => NULL,
          'configuration' => 
          array (
          ),
          'quota' => 
          array (
            'storage' => NULL,
            'bandwidth' => NULL,
            'requests' => NULL,
            'users' => NULL,
            'groups' => NULL,
          ),
          'usage' => 
          array (
            'storage' => 0,
            'bandwidth' => 0,
            'requests' => 0,
            'users' => 0,
            'groups' => 0,
          ),
          'deleted' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
        ),
      ),
      'schemas' => 
      array (
        2 => 
        array (
          'id' => 2,
          'uuid' => NULL,
          'uri' => NULL,
          'slug' => NULL,
          'title' => 'schema 2',
          'description' => NULL,
          'version' => NULL,
          'summary' => NULL,
          'icon' => NULL,
          'required' => 
          array (
          ),
          'properties' => 
          array (
          ),
          'archive' => 
          array (
          ),
          'source' => NULL,
          'hardValidation' => true,
          'immutable' => false,
          'appendOnly' => false,
          'searchable' => true,
          'smartPickerEnabled' => false,
          'updated' => NULL,
          'created' => NULL,
          'maxDepth' => 0,
          'owner' => NULL,
          'application' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'sharedWith' => 
          array (
          ),
          'deleted' => NULL,
          'configuration' => NULL,
          'exportable' => false,
          'allOf' => NULL,
          'oneOf' => NULL,
          'anyOf' => NULL,
          'facets' => NULL,
          'hooks' => NULL,
          '_mail' => NULL,
          '_contacts' => NULL,
          '_notes' => NULL,
          '_todos' => NULL,
          '_calendar' => NULL,
          '_talk' => NULL,
          '_deck' => NULL,
          'draft' => NULL,
        ),
      ),
      'ignoredFilters' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'magic_mapper',
      ),
    ),
  ),
  'text search no pairs' => 
  array (
    'calls' => 
    array (
      0 => 'getAllRegisterSchemaPairs []',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'magic_mapper',
      ),
    ),
  ),
  'text search blank' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'text search with a schema' => 
  array (
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [MagicMapper] searchObjectsPaginated() called without register/schema context []',
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
  'ids before relations before search' => 
  array (
    'calls' => 
    array (
      0 => 'findMultiple [["u1"],false,null]',
    ),
    'log' => 
    array (
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'registers' => 
      array (
      ),
      'schemas' => 
      array (
      ),
    ),
  ),
);
