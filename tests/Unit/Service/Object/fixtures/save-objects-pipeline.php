<?php

/**
 * Recorded outcomes for SaveObjectsPipelineGoldenTest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

return array (
  'transform' => 
  array (
    'value' => 
    array (
      'valid' => 
      array (
        0 => 
        array (
          'id' => 'given-1',
          'register' => 10,
          'schema' => 7,
          'object' => 
          array (
            'a' => 4,
          ),
          'relations' => 
          array (
            'x' => 'y',
          ),
          'uuid' => 'given-1',
          'owner' => 'bob',
          'organisation' => 'org-1',
        ),
        1 => 
        array (
          'id' => 'given-2',
          'register' => 10,
          'schema' => 7,
          'organisation' => 'org-1',
          'uuid' => 'given-2',
          'owner' => 'bob',
          'relations' => 
          array (
          ),
          'object' => 
          array (
            'name' => 'kept',
            'title' => 'gone?',
          ),
        ),
        2 => 
        array (
          'register' => 10,
          'schema' => 7,
          'object' => 
          array (
            'ref' => '22222222-2222-4222-8222-222222222222',
          ),
          'uuid' => 'generated-uuid',
          'id' => 'generated-uuid',
          'owner' => 'bob',
          'organisation' => 'org-1',
          'relations' => 
          array (
            'ref' => '22222222-2222-4222-8222-222222222222',
          ),
        ),
        3 => 
        array (
          'id' => 'generated-uuid',
          'register' => 10,
          'schema' => 7,
          'description' => 'd',
          'owner' => 'bob',
          'extra' => 1,
          'uuid' => 'generated-uuid',
          'organisation' => 'org-1',
          'relations' => 
          array (
          ),
          'object' => 
          array (
            'extra' => 1,
          ),
        ),
      ),
      'invalid' => 
      array (
        0 => 
        array (
          'object' => 
          array (
            'register' => false,
            'schema' => 7,
            'object' => 
            array (
              'a' => 1,
            ),
          ),
          'error' => 'Register ID is required but not found in object data or method parameters',
          'index' => 0,
          'type' => 'MissingRegisterException',
        ),
        1 => 
        array (
          'object' => 
          array (
            'register' => 10,
            'schema' => false,
            'object' => 
            array (
              'a' => 2,
            ),
          ),
          'error' => 'Schema ID is required but not found in object data or method parameters',
          'index' => 1,
          'type' => 'MissingSchemaException',
        ),
        2 => 
        array (
          'object' => 
          array (
            'register' => 10,
            'schema' => 99,
            'object' => 
            array (
              'a' => 3,
            ),
          ),
          'error' => 'Schema ID 99 does not exist or could not be loaded',
          'index' => 2,
          'type' => 'InvalidSchemaException',
        ),
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] DEBUG - Mixed schema object structure {"available_keys":["id","register","schema","object","relations"],"has_object_property":true}',
      1 => 'debug: [SaveObjects] Using object property for business data (mixed) []',
      2 => 'debug: [SaveObjects] Relations already set from preparation {"uuid":"given-1","relationCount":1}',
      3 => 'debug: [SaveObjects] DEBUG - Mixed schema object structure {"available_keys":["@self","name","title","uuid","slug"],"has_object_property":false}',
      4 => 'debug: [SaveObjects] Metadata removal applied (mixed) {"removed_fields":["@self","uuid","slug"],"kept_as_schema_properties":["name"],"remaining_keys":["name","title"]}',
      5 => 'debug: [SaveObjects] Relations scanned in transformation {"uuid":"given-2","relationCount":0}',
      6 => 'debug: [SaveObjects] DEBUG - Mixed schema object structure {"available_keys":["register","schema","object"],"has_object_property":true}',
      7 => 'debug: [SaveObjects] Using object property for business data (mixed) []',
      8 => 'debug: [SaveObjects] Relations scanned in transformation {"uuid":"generated-uuid","relationCount":1}',
      9 => 'debug: [SaveObjects] DEBUG - Mixed schema object structure {"available_keys":["id","register","schema","description","owner","extra"],"has_object_property":false}',
      10 => 'debug: [SaveObjects] Metadata removal applied (mixed) {"removed_fields":["register","schema","owner","id","description"],"kept_as_schema_properties":[],"remaining_keys":["extra"]}',
      11 => 'debug: [SaveObjects] Relations scanned in transformation {"uuid":"generated-uuid","relationCount":0}',
    ),
  ),
  'transform chunk' => 
  array (
    'value' => 
    array (
      'valid' => 
      array (
        0 => 
        array (
          'id' => 'given-1',
          'register' => 10,
          'schema' => 7,
          'object' => 
          array (
            'a' => 4,
          ),
          'relations' => 
          array (
            'x' => 'y',
          ),
          'uuid' => 'given-1',
          'owner' => 'bob',
          'organisation' => 'org-1',
        ),
        1 => 
        array (
          'id' => 'given-2',
          'register' => 10,
          'schema' => 7,
          'organisation' => 'org-1',
          'uuid' => 'given-2',
          'owner' => 'bob',
          'relations' => 
          array (
          ),
          'object' => 
          array (
            'name' => 'kept',
            'title' => 'gone?',
          ),
        ),
        2 => 
        array (
          'register' => 10,
          'schema' => 7,
          'object' => 
          array (
            'ref' => '22222222-2222-4222-8222-222222222222',
          ),
          'uuid' => 'generated-uuid',
          'id' => 'generated-uuid',
          'owner' => 'bob',
          'organisation' => 'org-1',
          'relations' => 
          array (
            'ref' => '22222222-2222-4222-8222-222222222222',
          ),
        ),
        3 => 
        array (
          'id' => 'generated-uuid',
          'register' => 10,
          'schema' => 7,
          'description' => 'd',
          'owner' => 'bob',
          'extra' => 1,
          'uuid' => 'generated-uuid',
          'organisation' => 'org-1',
          'relations' => 
          array (
          ),
          'object' => 
          array (
            'extra' => 1,
          ),
        ),
      ),
      'result' => 
      array (
        'saved' => 
        array (
        ),
        'updated' => 
        array (
        ),
        'unchanged' => 
        array (
        ),
        'invalid' => 
        array (
          0 => 
          array (
            'object' => 
            array (
              'register' => false,
              'schema' => 7,
              'object' => 
              array (
                'a' => 1,
              ),
            ),
            'error' => 'Register ID is required but not found in object data or method parameters',
            'index' => 0,
            'type' => 'MissingRegisterException',
          ),
          1 => 
          array (
            'object' => 
            array (
              'register' => 10,
              'schema' => false,
              'object' => 
              array (
                'a' => 2,
              ),
            ),
            'error' => 'Schema ID is required but not found in object data or method parameters',
            'index' => 1,
            'type' => 'MissingSchemaException',
          ),
          2 => 
          array (
            'object' => 
            array (
              'register' => 10,
              'schema' => 99,
              'object' => 
              array (
                'a' => 3,
              ),
            ),
            'error' => 'Schema ID 99 does not exist or could not be loaded',
            'index' => 2,
            'type' => 'InvalidSchemaException',
          ),
        ),
        'errors' => 
        array (
        ),
        'statistics' => 
        array (
          'saved' => 0,
          'updated' => 0,
          'unchanged' => 0,
          'invalid' => 3,
          'errors' => 3,
        ),
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] DEBUG - Mixed schema object structure {"available_keys":["id","register","schema","object","relations"],"has_object_property":true}',
      1 => 'debug: [SaveObjects] Using object property for business data (mixed) []',
      2 => 'debug: [SaveObjects] Relations already set from preparation {"uuid":"given-1","relationCount":1}',
      3 => 'debug: [SaveObjects] DEBUG - Mixed schema object structure {"available_keys":["@self","name","title","uuid","slug"],"has_object_property":false}',
      4 => 'debug: [SaveObjects] Metadata removal applied (mixed) {"removed_fields":["@self","uuid","slug"],"kept_as_schema_properties":["name"],"remaining_keys":["name","title"]}',
      5 => 'debug: [SaveObjects] Relations scanned in transformation {"uuid":"given-2","relationCount":0}',
      6 => 'debug: [SaveObjects] DEBUG - Mixed schema object structure {"available_keys":["register","schema","object"],"has_object_property":true}',
      7 => 'debug: [SaveObjects] Using object property for business data (mixed) []',
      8 => 'debug: [SaveObjects] Relations scanned in transformation {"uuid":"generated-uuid","relationCount":1}',
      9 => 'debug: [SaveObjects] DEBUG - Mixed schema object structure {"available_keys":["id","register","schema","description","owner","extra"],"has_object_property":false}',
      10 => 'debug: [SaveObjects] Metadata removal applied (mixed) {"removed_fields":["register","schema","owner","id","description"],"kept_as_schema_properties":[],"remaining_keys":["extra"]}',
      11 => 'debug: [SaveObjects] Relations scanned in transformation {"uuid":"generated-uuid","relationCount":0}',
    ),
  ),
  'extract new structure' => 
  array (
    'value' => 
    array (
      'k' => 'v',
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] Using object property for business data (mixed) []',
    ),
  ),
  'extract legacy declared' => 
  array (
    'value' => 
    array (
      'name' => 'n',
      'k' => 'v',
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] Metadata removal applied (mixed) {"removed_fields":["@self","uuid","summary"],"kept_as_schema_properties":["name"],"remaining_keys":["name","k"]}',
    ),
  ),
  'extract legacy no schema' => 
  array (
    'value' => 
    array (
      'k' => 'v',
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] Metadata removal applied (mixed) {"removed_fields":["name","image"],"kept_as_schema_properties":[],"remaining_keys":["k"]}',
    ),
  ),
  'required fields' => 
  array (
    'value' => 
    array (
      0 => 
      array (
        'object' => 
        array (
          'o' => 1,
        ),
        'error' => 'Register ID is required but not found in object data or method parameters',
        'index' => 0,
        'type' => 'MissingRegisterException',
      ),
      1 => 
      array (
        'object' => 
        array (
          'o' => 2,
        ),
        'error' => 'Schema ID is required but not found in object data or method parameters',
        'index' => 1,
        'type' => 'MissingSchemaException',
      ),
      2 => 
      array (
        'object' => 
        array (
          'o' => 3,
        ),
        'error' => 'Schema ID 5 does not exist or could not be loaded',
        'index' => 2,
        'type' => 'InvalidSchemaException',
      ),
      3 => NULL,
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'persist ids' => 
  array (
    'value' => 
    array (
      0 => 'saved',
    ),
    'calls' => 
    array (
      0 => 'register find 10',
      1 => 'schema find 7',
      2 => 'ultraFastBulkSave [[{"uuid":"a"}],[],10,7,false]',
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] Using single-call bulk processing (no pre-lookup needed) {"objects_to_process":1,"approach":"INSERT...ON DUPLICATE KEY UPDATE with database-computed classification"}',
    ),
  ),
  'persist failing ids' => 
  array (
    'value' => 
    array (
      0 => 'saved',
    ),
    'calls' => 
    array (
      0 => 'register find 404',
      1 => 'schema find 404',
      2 => 'ultraFastBulkSave [[{"uuid":"a"}],[],null,null,true]',
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] Using single-call bulk processing (no pre-lookup needed) {"objects_to_process":1,"approach":"INSERT...ON DUPLICATE KEY UPDATE with database-computed classification"}',
      1 => 'warning: [SaveObjects] Failed to resolve register for bulk save {"id":404}',
      2 => 'warning: [SaveObjects] Failed to resolve schema for bulk save {"id":404}',
    ),
  ),
  'persist entities' => 
  array (
    'value' => 
    array (
      0 => 'saved',
    ),
    'calls' => 
    array (
      0 => 'ultraFastBulkSave [[{"uuid":"a"}],[],11,7,true]',
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] Using single-call bulk processing (no pre-lookup needed) {"objects_to_process":1,"approach":"INSERT...ON DUPLICATE KEY UPDATE with database-computed classification"}',
    ),
  ),
  'persist nothing named' => 
  array (
    'value' => 
    array (
      0 => 'saved',
    ),
    'calls' => 
    array (
      0 => 'ultraFastBulkSave [[{"uuid":"a"}],[],null,null,true]',
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] Using single-call bulk processing (no pre-lookup needed) {"objects_to_process":1,"approach":"INSERT...ON DUPLICATE KEY UPDATE with database-computed classification"}',
    ),
  ),
  'build non array' => 
  array (
    'value' => 
    array (
      'sideEffects' => 
      array (
        'created' => 
        array (
        ),
        'updated' => 
        array (
        ),
      ),
      'result' => 
      array (
        'saved' => 
        array (
          0 => 
          array (
            'uuid' => 'a',
          ),
          1 => 
          array (
            'uuid' => 'b',
          ),
        ),
        'updated' => 
        array (
        ),
        'unchanged' => 
        array (
        ),
        'invalid' => 
        array (
        ),
        'errors' => 
        array (
        ),
        'statistics' => 
        array (
          'saved' => 2,
          'updated' => 0,
          'unchanged' => 0,
          'invalid' => 0,
          'errors' => 0,
        ),
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [SaveObjects] Unexpected bulk result format, using fallback []',
    ),
  ),
  'build legacy uuids' => 
  array (
    'value' => 
    array (
      'sideEffects' => 
      array (
        'created' => 
        array (
        ),
        'updated' => 
        array (
        ),
      ),
      'result' => 
      array (
        'saved' => 
        array (
          0 => 
          array (
            '@self' => 
            array (
              'id' => 'a',
              'slug' => NULL,
              'name' => 'a',
              'description' => NULL,
              'summary' => NULL,
              'image' => NULL,
              'uri' => NULL,
              'version' => NULL,
              'register' => NULL,
              'schema' => NULL,
              'schemaVersion' => NULL,
              'files' => 
              array (
              ),
              'relations' => 
              array (
              ),
              'locked' => NULL,
              'owner' => NULL,
              'organisation' => NULL,
              'groups' => 
              array (
              ),
              'authorization' => 
              array (
              ),
              'folder' => NULL,
              'application' => NULL,
              'validation' => 
              array (
              ),
              'quality' => 
              array (
              ),
              'geo' => 
              array (
              ),
              'retention' => 
              array (
              ),
              'tmlo' => 
              array (
              ),
              'size' => NULL,
              'updated' => NULL,
              'lastUserActivity' => NULL,
              'created' => NULL,
              'deleted' => 
              array (
              ),
              'archived' => 
              array (
              ),
              'frozen' => 
              array (
              ),
              'status' => NULL,
              'source' => NULL,
              'mail' => NULL,
              'contacts' => NULL,
              'notes' => NULL,
              'todos' => NULL,
              'calendar' => NULL,
              'talk' => NULL,
              'deck' => NULL,
            ),
            'id' => 'a',
          ),
        ),
        'updated' => 
        array (
        ),
        'unchanged' => 
        array (
        ),
        'invalid' => 
        array (
        ),
        'errors' => 
        array (
        ),
        'statistics' => 
        array (
          'saved' => 1,
          'updated' => 0,
          'unchanged' => 0,
          'invalid' => 0,
          'errors' => 0,
        ),
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [SaveObjects] Legacy uuid-array bulk result — audit trail and lifecycle events are skipped for this chunk {"objects":3}',
      1 => 'debug: [SaveObjects] Processing UUID array (legacy mode) []',
      2 => 'error: Object reconstruction failed: Missing UUID after bulk save operation {"objectKeys":["object"],"error":"UUID missing in saved object data","context":"reconstructSavedObjects"}',
      3 => 'debug: [SaveObjects] Using fallback object reconstruction []',
    ),
  ),
  'build empty' => 
  array (
    'value' => 
    array (
      'sideEffects' => 
      array (
        'created' => 
        array (
        ),
        'updated' => 
        array (
        ),
      ),
      'result' => 
      array (
        'saved' => 
        array (
        ),
        'updated' => 
        array (
        ),
        'unchanged' => 
        array (
        ),
        'invalid' => 
        array (
        ),
        'errors' => 
        array (
        ),
        'statistics' => 
        array (
          'saved' => 0,
          'updated' => 0,
          'unchanged' => 0,
          'invalid' => 0,
          'errors' => 0,
        ),
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'warning: [SaveObjects] Legacy uuid-array bulk result — audit trail and lifecycle events are skipped for this chunk {"objects":0}',
      1 => 'debug: [SaveObjects] Processing UUID array (legacy mode) []',
      2 => 'debug: [SaveObjects] Using fallback object reconstruction []',
    ),
  ),
  'build classified' => 
  array (
    'value' => 
    array (
      'sideEffects' => 
      array (
        'created' => 
        array (
          0 => 
          array (
            'entity' => 'c1',
            'object' => 
            array (
              'id' => 'c1',
              'from' => 'row',
            ),
            'name' => NULL,
          ),
        ),
        'updated' => 
        array (
          0 => 
          array (
            'old' => 
            array (
              'entity' => 'u1',
              'object' => 
              array (
                'id' => 'u1',
                'from' => 'row',
              ),
              'name' => NULL,
            ),
            'new' => 
            array (
              'entity' => 'u1',
              'object' => 
              array (
                'id' => 'u1',
                'from' => 'row',
              ),
              'name' => NULL,
            ),
          ),
          1 => 
          array (
            'old' => NULL,
            'new' => 
            array (
              'entity' => 'u2',
              'object' => 
              array (
                'id' => 'u2',
                'from' => 'row',
              ),
              'name' => NULL,
            ),
          ),
        ),
      ),
      'result' => 
      array (
        'saved' => 
        array (
          0 => 
          array (
            'from' => 'row',
            '@self' => 
            array (
              'id' => 'c1',
              'slug' => NULL,
              'name' => 'c1',
              'description' => NULL,
              'summary' => NULL,
              'image' => NULL,
              'uri' => NULL,
              'version' => NULL,
              'register' => NULL,
              'schema' => NULL,
              'schemaVersion' => NULL,
              'files' => 
              array (
              ),
              'relations' => 
              array (
              ),
              'locked' => NULL,
              'owner' => NULL,
              'organisation' => NULL,
              'groups' => 
              array (
              ),
              'authorization' => 
              array (
              ),
              'folder' => NULL,
              'application' => NULL,
              'validation' => 
              array (
              ),
              'quality' => 
              array (
              ),
              'geo' => 
              array (
              ),
              'retention' => 
              array (
              ),
              'tmlo' => 
              array (
              ),
              'size' => NULL,
              'updated' => NULL,
              'lastUserActivity' => NULL,
              'created' => NULL,
              'deleted' => 
              array (
              ),
              'archived' => 
              array (
              ),
              'frozen' => 
              array (
              ),
              'status' => NULL,
              'source' => NULL,
              'mail' => NULL,
              'contacts' => NULL,
              'notes' => NULL,
              'todos' => NULL,
              'calendar' => NULL,
              'talk' => NULL,
              'deck' => NULL,
            ),
            'id' => 'c1',
          ),
          1 => 
          array (
            'uuid' => 'x2',
          ),
          2 => 
          array (
            '_uuid' => 'f1',
            '_register' => 10,
            '_schema' => 404,
          ),
          3 => 
          array (
            '_uuid' => 'b1',
            '_register' => 10,
            '_schema' => 7,
            'boom' => true,
          ),
        ),
        'updated' => 
        array (
          0 => 
          array (
            'from' => 'row',
            '@self' => 
            array (
              'id' => 'u1',
              'slug' => NULL,
              'name' => 'u1',
              'description' => NULL,
              'summary' => NULL,
              'image' => NULL,
              'uri' => NULL,
              'version' => NULL,
              'register' => NULL,
              'schema' => NULL,
              'schemaVersion' => NULL,
              'files' => 
              array (
              ),
              'relations' => 
              array (
              ),
              'locked' => NULL,
              'owner' => NULL,
              'organisation' => NULL,
              'groups' => 
              array (
              ),
              'authorization' => 
              array (
              ),
              'folder' => NULL,
              'application' => NULL,
              'validation' => 
              array (
              ),
              'quality' => 
              array (
              ),
              'geo' => 
              array (
              ),
              'retention' => 
              array (
              ),
              'tmlo' => 
              array (
              ),
              'size' => NULL,
              'updated' => NULL,
              'lastUserActivity' => NULL,
              'created' => NULL,
              'deleted' => 
              array (
              ),
              'archived' => 
              array (
              ),
              'frozen' => 
              array (
              ),
              'status' => NULL,
              'source' => NULL,
              'mail' => NULL,
              'contacts' => NULL,
              'notes' => NULL,
              'todos' => NULL,
              'calendar' => NULL,
              'talk' => NULL,
              'deck' => NULL,
            ),
            'id' => 'u1',
          ),
          1 => 
          array (
            'from' => 'row',
            '@self' => 
            array (
              'id' => 'u2',
              'slug' => NULL,
              'name' => 'u2',
              'description' => NULL,
              'summary' => NULL,
              'image' => NULL,
              'uri' => NULL,
              'version' => NULL,
              'register' => NULL,
              'schema' => NULL,
              'schemaVersion' => NULL,
              'files' => 
              array (
              ),
              'relations' => 
              array (
              ),
              'locked' => NULL,
              'owner' => NULL,
              'organisation' => NULL,
              'groups' => 
              array (
              ),
              'authorization' => 
              array (
              ),
              'folder' => NULL,
              'application' => NULL,
              'validation' => 
              array (
              ),
              'quality' => 
              array (
              ),
              'geo' => 
              array (
              ),
              'retention' => 
              array (
              ),
              'tmlo' => 
              array (
              ),
              'size' => NULL,
              'updated' => NULL,
              'lastUserActivity' => NULL,
              'created' => NULL,
              'deleted' => 
              array (
              ),
              'archived' => 
              array (
              ),
              'frozen' => 
              array (
              ),
              'status' => NULL,
              'source' => NULL,
              'mail' => NULL,
              'contacts' => NULL,
              'notes' => NULL,
              'todos' => NULL,
              'calendar' => NULL,
              'talk' => NULL,
              'deck' => NULL,
            ),
            'id' => 'u2',
          ),
        ),
        'unchanged' => 
        array (
          0 => 
          array (
            'from' => 'row',
            '@self' => 
            array (
              'id' => 'n1',
              'slug' => NULL,
              'name' => 'n1',
              'description' => NULL,
              'summary' => NULL,
              'image' => NULL,
              'uri' => NULL,
              'version' => NULL,
              'register' => NULL,
              'schema' => NULL,
              'schemaVersion' => NULL,
              'files' => 
              array (
              ),
              'relations' => 
              array (
              ),
              'locked' => NULL,
              'owner' => NULL,
              'organisation' => NULL,
              'groups' => 
              array (
              ),
              'authorization' => 
              array (
              ),
              'folder' => NULL,
              'application' => NULL,
              'validation' => 
              array (
              ),
              'quality' => 
              array (
              ),
              'geo' => 
              array (
              ),
              'retention' => 
              array (
              ),
              'tmlo' => 
              array (
              ),
              'size' => NULL,
              'updated' => NULL,
              'lastUserActivity' => NULL,
              'created' => NULL,
              'deleted' => 
              array (
              ),
              'archived' => 
              array (
              ),
              'frozen' => 
              array (
              ),
              'status' => NULL,
              'source' => NULL,
              'mail' => NULL,
              'contacts' => NULL,
              'notes' => NULL,
              'todos' => NULL,
              'calendar' => NULL,
              'talk' => NULL,
              'deck' => NULL,
            ),
            'id' => 'n1',
          ),
          1 => 
          array (
            'from' => 'row',
            '@self' => 
            array (
              'id' => 'x1',
              'slug' => NULL,
              'name' => 'x1',
              'description' => NULL,
              'summary' => NULL,
              'image' => NULL,
              'uri' => NULL,
              'version' => NULL,
              'register' => NULL,
              'schema' => NULL,
              'schemaVersion' => NULL,
              'files' => 
              array (
              ),
              'relations' => 
              array (
              ),
              'locked' => NULL,
              'owner' => NULL,
              'organisation' => NULL,
              'groups' => 
              array (
              ),
              'authorization' => 
              array (
              ),
              'folder' => NULL,
              'application' => NULL,
              'validation' => 
              array (
              ),
              'quality' => 
              array (
              ),
              'geo' => 
              array (
              ),
              'retention' => 
              array (
              ),
              'tmlo' => 
              array (
              ),
              'size' => NULL,
              'updated' => NULL,
              'lastUserActivity' => NULL,
              'created' => NULL,
              'deleted' => 
              array (
              ),
              'archived' => 
              array (
              ),
              'frozen' => 
              array (
              ),
              'status' => NULL,
              'source' => NULL,
              'mail' => NULL,
              'contacts' => NULL,
              'notes' => NULL,
              'todos' => NULL,
              'calendar' => NULL,
              'talk' => NULL,
              'deck' => NULL,
            ),
            'id' => 'x1',
          ),
        ),
        'invalid' => 
        array (
        ),
        'errors' => 
        array (
        ),
        'statistics' => 
        array (
          'saved' => 4,
          'updated' => 2,
          'unchanged' => 2,
          'invalid' => 0,
          'errors' => 0,
        ),
      ),
    ),
    'calls' => 
    array (
      0 => 'register find 10',
      1 => 'schema find 7',
      2 => 'convert ["c1",10,7]',
      3 => 'convert ["u1",10,7]',
      4 => 'convert ["u1",10,7]',
      5 => 'convert ["u2",10,7]',
      6 => 'convert ["n1",10,7]',
      7 => 'convert ["x1",10,7]',
      8 => 'schema find 404',
      9 => 'convert ["b1",10,7]',
    ),
    'log' => 
    array (
      0 => 'debug: [SaveObjects] Processing complete objects with database-computed classification []',
      1 => 'warning: Unexpected object status: weird {"uuid":"x1","object_status":"weird"}',
      2 => 'warning: [SaveObjects] Failed to convert bulk result row to entity {"error":"no schema","uuid":"f1","register":10,"schema":404}',
      3 => 'warning: [SaveObjects] Failed to convert bulk result row to entity {"error":"cannot convert","uuid":"b1","register":10,"schema":7}',
      4 => 'debug: [SaveObjects] Database-computed classification completed {"total_processed":8,"created_objects":4,"updated_objects":2,"unchanged_objects":2,"classification_method":"database_computed_sql"}',
    ),
  ),
  'reconstruct' => 
  array (
    'value' => 
    array (
      0 => 
      array (
        'entity' => 'a',
        'object' => 
        array (
          'id' => 'a',
        ),
        'name' => 'A',
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'error: Object reconstruction failed: Missing UUID after bulk save operation {"objectKeys":["uuid"],"error":"UUID missing in saved object data","context":"reconstructSavedObjects"}',
      1 => 'error: Object reconstruction failed: Missing UUID after bulk save operation {"objectKeys":["name"],"error":"UUID missing in saved object data","context":"reconstructSavedObjects"}',
    ),
  ),
  'result shapes' => 
  array (
    'value' => 
    array (
      0 => 
      array (
        'saved' => 
        array (
        ),
        'updated' => 
        array (
        ),
        'unchanged' => 
        array (
        ),
        'invalid' => 
        array (
        ),
        'errors' => 
        array (
        ),
        'statistics' => 
        array (
          'totalProcessed' => 3,
          'saved' => 0,
          'updated' => 0,
          'unchanged' => 0,
          'invalid' => 0,
          'errors' => 0,
          'processingTimeMs' => 0,
        ),
      ),
      1 => 
      array (
        'saved' => 
        array (
        ),
        'updated' => 
        array (
        ),
        'unchanged' => 
        array (
        ),
        'invalid' => 
        array (
        ),
        'errors' => 
        array (
        ),
        'statistics' => 
        array (
          'saved' => 0,
          'updated' => 0,
          'unchanged' => 0,
          'invalid' => 0,
          'errors' => 0,
        ),
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'hydrated metadata' => 
  array (
    'value' => 
    array (
      'self' => 
      array (
        'id' => 'i',
        'name' => 'N',
        'slug' => 's-1',
      ),
      'object' => 
      array (
        'title' => 't',
        'name' => 'N',
        'slug' => 's-1',
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'decision tables off' => 
  array (
    'value' => 
    array (
      'passed' => 
      array (
        0 => 
        array (
          'a' => 0,
        ),
        1 => 
        array (
          'a' => 1,
        ),
        'k' => 
        array (
          'a' => 'k',
        ),
        3 => 
        array (
          'a' => 3,
        ),
      ),
      'result' => 
      array (
        'invalid' => 
        array (
        ),
        'errors' => 
        array (
        ),
        'statistics' => 
        array (
          'invalid' => 0,
          'errors' => 0,
        ),
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'decision tables refuse' => 
  array (
    'value' => 
    array (
      'passed' => 
      array (
        0 => 
        array (
          'a' => 0,
        ),
        3 => 
        array (
          'a' => 3,
        ),
      ),
      'result' => 
      array (
        'invalid' => 
        array (
          0 => 
          array (
            'object' => 
            array (
              'a' => 1,
            ),
            'error' => 'bad table',
            'index' => 1,
            'type' => 'BulkSafeguardException',
          ),
          1 => 
          array (
            'object' => 
            array (
              'a' => 'k',
            ),
            'error' => 'bad key',
            'index' => NULL,
            'type' => 'BulkSafeguardException',
          ),
        ),
        'errors' => 
        array (
          0 => 
          array (
            'error' => 'bad table',
            'type' => 'BulkSafeguardException',
            'index' => 1,
          ),
          1 => 
          array (
            'error' => 'bad key',
            'type' => 'BulkSafeguardException',
            'index' => NULL,
          ),
        ),
        'statistics' => 
        array (
          'invalid' => 2,
          'errors' => 2,
        ),
      ),
    ),
    'calls' => 
    array (
      0 => 'refusals [[0,1,"k",3],10,7]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"bad table","index":1}',
      1 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"bad key","index":null}',
    ),
  ),
);
