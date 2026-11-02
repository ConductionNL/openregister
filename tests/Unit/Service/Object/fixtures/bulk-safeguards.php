<?php

/**
 * Recorded outcomes for SaveObjectsBulkSafeguardsGoldenTest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

return array (
  'no permission handler' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'non-admin create allowed' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'non-admin create denied' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":0}',
    ),
  ),
  'admin skips rbac when asked' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'admin keeps rbac by default' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","admin",null,true,null]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":0}',
    ),
  ),
  'non-admin cannot skip rbac' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":0}',
    ),
  ),
  'no session user' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create",null,null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'admin lookup throws' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","throws",null,true,null]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":0}',
    ),
  ),
  'no group manager' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":0}',
    ),
  ),
  'non-admin self injection stripped' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'name' => 'ok',
        ),
        'title' => 't',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'admin self injection kept' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'organisation' => 'org',
          'owner' => 'root',
          'name' => 'ok',
        ),
        'title' => 't',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","admin",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'ambiguous identity' => 
  array (
    'passed' => 
    array (
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
            'id' => '11111111-1111-1111-1111-111111111111',
            '@self' => 
            array (
              'id' => '33333333-3333-3333-3333-333333333333',
            ),
          ),
          'error' => 'The row names more than one identity (id, @self.id, @self.uuid differ); send one.',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'The row names more than one identity (id, @self.id, @self.uuid differ); send one.',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"The row names more than one identity (id, @self.id, @self.uuid differ); send one.","index":0}',
    ),
  ),
  'agreeing identities' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'id' => '11111111-1111-1111-1111-111111111111',
        '@self' => 
        array (
          'id' => '11111111-1111-1111-1111-111111111111',
          'uuid' => '11111111-1111-1111-1111-111111111111',
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
      ),
      'errors' => 
      array (
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      1 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
    ),
    'log' => 
    array (
    ),
  ),
  'mixed batch without schema' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'schema zero' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'schema zero string' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'schema by id' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'schema find 7',
      1 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'schema unresolvable' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Schema could not be resolved; refusing to write a row whose permissions cannot be checked.',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
        1 => 
        array (
          'object' => 
          array (
            '@self' => 
            array (
              'uuid' => '11111111-1111-1111-1111-111111111111',
            ),
            'title' => 'changed',
          ),
          'error' => 'Schema could not be resolved; refusing to write a row whose permissions cannot be checked.',
          'index' => 1,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Schema could not be resolved; refusing to write a row whose permissions cannot be checked.',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
        1 => 
        array (
          'error' => 'Schema could not be resolved; refusing to write a row whose permissions cannot be checked.',
          'type' => 'BulkSafeguardException',
          'index' => 1,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 2,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 2,
        'errors' => 2,
      ),
    ),
    'calls' => 
    array (
      0 => 'schema find 404',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Schema could not be resolved; refusing to write a row whose permissions cannot be checked.","index":0}',
      1 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Schema could not be resolved; refusing to write a row whose permissions cannot be checked.","index":1}',
    ),
  ),
  'schema unresolvable, admin no rbac' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'schema find 404',
    ),
    'log' => 
    array (
    ),
  ),
  'row names its own schema' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'schema' => 8,
        ),
        'title' => 'x',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'schema find 8',
      1 => 'hasPermission [8,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'row names an unknown schema' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'schema' => 404,
        ),
        'title' => 'x',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'schema find 404',
      1 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'register by id' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'changed',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'register find 10',
      1 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      2 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
    ),
    'log' => 
    array (
    ),
  ),
  'register unresolvable' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'changed',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'register find 404',
      1 => 'find ["11111111-1111-1111-1111-111111111111",null,null]',
      2 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
    ),
    'log' => 
    array (
    ),
  ),
  'register zero' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'changed',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'find ["11111111-1111-1111-1111-111111111111",null,null]',
      1 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
    ),
    'log' => 
    array (
    ),
  ),
  'row names its own register' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'register' => 11,
          'uuid' => '11111111-1111-1111-1111-111111111111',
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
      ),
      'errors' => 
      array (
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'register find 11',
      1 => 'prefetch [["11111111-1111-1111-1111-111111111111"],11,7]',
      2 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
    ),
    'log' => 
    array (
    ),
  ),
  'update allowed' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'changed',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      1 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
    ),
    'log' => 
    array (
    ),
  ),
  'update denied' => 
  array (
    'passed' => 
    array (
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
            '@self' => 
            array (
              'uuid' => '11111111-1111-1111-1111-111111111111',
            ),
            'title' => 'changed',
          ),
          'error' => 'Permission denied for action update on schema schema-7',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action update on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      1 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action update on schema schema-7","index":0}',
    ),
  ),
  'update with unknown uuid' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '33333333-3333-3333-3333-333333333333',
        ),
        'title' => 'x',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["33333333-3333-3333-3333-333333333333"],10,7]',
      1 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'prefetch fails' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'changed',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      1 => 'find ["11111111-1111-1111-1111-111111111111",10,7]',
      2 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
    ),
    'log' => 
    array (
      0 => 'warning: [SaveObjects] Batch existence prefetch failed; falling back to per-row lookup {"group":"10\\/7","uuids":1,"error":"probe failed"}',
    ),
  ),
  'prefetch fails and find fails' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'changed',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      1 => 'find ["11111111-1111-1111-1111-111111111111",10,7]',
      2 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
      0 => 'warning: [SaveObjects] Batch existence prefetch failed; falling back to per-row lookup {"group":"10\\/7","uuids":1,"error":"probe failed"}',
    ),
  ),
  'append-only update' => 
  array (
    'passed' => 
    array (
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
            '@self' => 
            array (
              'uuid' => '11111111-1111-1111-1111-111111111111',
            ),
            'title' => 'changed',
          ),
          'error' => 'Schema is appendOnly; UPDATE rejected for row UUID 11111111-1111-1111-1111-111111111111',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Schema is appendOnly; UPDATE rejected for row UUID 11111111-1111-1111-1111-111111111111',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Schema is appendOnly; UPDATE rejected for row UUID 11111111-1111-1111-1111-111111111111","index":0}',
    ),
  ),
  'append-only create' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'append-only update without rbac' => 
  array (
    'passed' => 
    array (
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
            '@self' => 
            array (
              'uuid' => '11111111-1111-1111-1111-111111111111',
            ),
            'title' => 'changed',
          ),
          'error' => 'Schema is appendOnly; UPDATE rejected for row UUID 11111111-1111-1111-1111-111111111111',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Schema is appendOnly; UPDATE rejected for row UUID 11111111-1111-1111-1111-111111111111',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Schema is appendOnly; UPDATE rejected for row UUID 11111111-1111-1111-1111-111111111111","index":0}',
    ),
  ),
  'somebody elses draft' => 
  array (
    'passed' => 
    array (
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
            '@self' => 
            array (
              'uuid' => '22222222-2222-2222-2222-222222222222',
            ),
            'title' => 'x',
          ),
          'error' => 'Permission denied for action update on schema schema-7',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action update on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["22222222-2222-2222-2222-222222222222"],10,7]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action update on schema schema-7","index":0}',
    ),
  ),
  'own draft' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '22222222-2222-2222-2222-222222222222',
        ),
        'title' => 'x',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["22222222-2222-2222-2222-222222222222"],10,7]',
      1 => 'hasPermission [7,"update","carol","carol",true,"22222222-2222-2222-2222-222222222222"]',
    ),
    'log' => 
    array (
    ),
  ),
  'admin over a draft' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '22222222-2222-2222-2222-222222222222',
        ),
        'title' => 'x',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["22222222-2222-2222-2222-222222222222"],10,7]',
      1 => 'hasPermission [7,"update","admin","carol",true,"22222222-2222-2222-2222-222222222222"]',
    ),
    'log' => 
    array (
    ),
  ),
  'draft on append-only schema' => 
  array (
    'passed' => 
    array (
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
            '@self' => 
            array (
              'uuid' => '22222222-2222-2222-2222-222222222222',
            ),
            'title' => 'x',
          ),
          'error' => 'Permission denied for action update on schema schema-7',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action update on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["22222222-2222-2222-2222-222222222222"],10,7]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action update on schema schema-7","index":0}',
    ),
  ),
  'draft without rbac' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '22222222-2222-2222-2222-222222222222',
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
      ),
      'errors' => 
      array (
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["22222222-2222-2222-2222-222222222222"],10,7]',
    ),
    'log' => 
    array (
    ),
  ),
  'property authorization refuses' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'You are not authorized to modify: secret',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
        1 => 
        array (
          'object' => 
          array (
            '@self' => 
            array (
              'uuid' => '11111111-1111-1111-1111-111111111111',
            ),
            'title' => 'changed',
          ),
          'error' => 'You are not authorized to modify: secret',
          'index' => 1,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'You are not authorized to modify: secret',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
        1 => 
        array (
          'error' => 'You are not authorized to modify: secret',
          'type' => 'BulkSafeguardException',
          'index' => 1,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 2,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 2,
        'errors' => 2,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      1 => 'hasPermission [7,"create","bob",null,true,null]',
      2 => 'getUnauthorizedProperties [[],{"title":"new"},true]',
      3 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
      4 => 'getUnauthorizedProperties [{"id":"11111111-1111-1111-1111-111111111111","title":"stored","secret":"s3cret"},{"title":"changed"},false]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"You are not authorized to modify: secret","index":0}',
      1 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"You are not authorized to modify: secret","index":1}',
    ),
  ),
  'property authorization restores' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'changed',
        'secret' => 's3cret',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      1 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
      2 => 'getUnauthorizedProperties [{"id":"11111111-1111-1111-1111-111111111111","title":"stored","secret":"s3cret"},{"title":"changed"},false]',
    ),
    'log' => 
    array (
    ),
  ),
  'property authorization no rbac' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
    ),
    'log' => 
    array (
    ),
  ),
  'validation passes' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
      1 => 'validateObject [{"title":"new"},7]',
    ),
    'log' => 
    array (
    ),
  ),
  'validation fails' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Schema validation failed: title is required',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
        1 => 
        array (
          'object' => 
          array (
            '@self' => 
            array (
              'uuid' => '11111111-1111-1111-1111-111111111111',
            ),
            'title' => 'changed',
          ),
          'error' => 'Schema validation failed: title is required',
          'index' => 1,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Schema validation failed: title is required',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
        1 => 
        array (
          'error' => 'Schema validation failed: title is required',
          'type' => 'BulkSafeguardException',
          'index' => 1,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 2,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 2,
        'errors' => 2,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111"],10,7]',
      1 => 'hasPermission [7,"create","bob",null,true,null]',
      2 => 'validateObject [{"title":"new"},7]',
      3 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
      4 => 'validateObject [{"title":"changed"},7]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Schema validation failed: title is required","index":0}',
      1 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Schema validation failed: title is required","index":1}',
    ),
  ),
  'validation throws' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Schema validation threw: validator broke',
          'index' => 0,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Schema validation threw: validator broke',
          'type' => 'BulkSafeguardException',
          'index' => 0,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 1,
        'errors' => 1,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
      1 => 'validateObject [{"title":"new"},7]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Schema validation threw: validator broke","index":0}',
    ),
  ),
  'validation without validator' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'validation not asked' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
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
        'totalProcessed' => 1,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
    ),
  ),
  'string row keys' => 
  array (
    'passed' => 
    array (
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
            'title' => 'new',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => NULL,
          'type' => 'BulkSafeguardException',
        ),
        1 => 
        array (
          'object' => 
          array (
            'title' => 'new',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => NULL,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => NULL,
        ),
        1 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => NULL,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 2,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 2,
        'errors' => 2,
      ),
    ),
    'calls' => 
    array (
      0 => 'hasPermission [7,"create","bob",null,true,null]',
      1 => 'hasPermission [7,"create","bob",null,true,null]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":null}',
      1 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":null}',
    ),
  ),
  'mixed batch in order' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        'title' => 'new',
      ),
      1 => 
      array (
        'title' => 'second new',
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
            'id' => '11111111-1111-1111-1111-111111111111',
            '@self' => 
            array (
              'id' => '33333333-3333-3333-3333-333333333333',
            ),
          ),
          'error' => 'The row names more than one identity (id, @self.id, @self.uuid differ); send one.',
          'index' => 1,
          'type' => 'BulkSafeguardException',
        ),
        1 => 
        array (
          'object' => 
          array (
            '@self' => 
            array (
              'uuid' => '11111111-1111-1111-1111-111111111111',
            ),
            'title' => 'changed',
          ),
          'error' => 'Permission denied for action update on schema schema-7',
          'index' => 2,
          'type' => 'BulkSafeguardException',
        ),
        2 => 
        array (
          'object' => 
          array (
            '@self' => 
            array (
              'uuid' => '22222222-2222-2222-2222-222222222222',
            ),
          ),
          'error' => 'Permission denied for action update on schema schema-7',
          'index' => 3,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'The row names more than one identity (id, @self.id, @self.uuid differ); send one.',
          'type' => 'BulkSafeguardException',
          'index' => 1,
        ),
        1 => 
        array (
          'error' => 'Permission denied for action update on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 2,
        ),
        2 => 
        array (
          'error' => 'Permission denied for action update on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 3,
        ),
      ),
      'statistics' => 
      array (
        'totalProcessed' => 5,
        'saved' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'invalid' => 3,
        'errors' => 3,
      ),
    ),
    'calls' => 
    array (
      0 => 'prefetch [["11111111-1111-1111-1111-111111111111","22222222-2222-2222-2222-222222222222"],10,7]',
      1 => 'hasPermission [7,"create","bob",null,true,null]',
      2 => 'validateObject [{"title":"new"},7]',
      3 => 'hasPermission [7,"update","bob","bob",true,"11111111-1111-1111-1111-111111111111"]',
      4 => 'hasPermission [7,"create","bob",null,true,null]',
      5 => 'validateObject [{"title":"second new"},7]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"The row names more than one identity (id, @self.id, @self.uuid differ); send one.","index":1}',
      1 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action update on schema schema-7","index":2}',
      2 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action update on schema schema-7","index":3}',
    ),
  ),
);
