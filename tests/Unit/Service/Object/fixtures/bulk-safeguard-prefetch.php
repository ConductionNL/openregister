<?php

/**
 * Recorded outcomes for SaveObjectsSafeguardPrefetchGoldenTest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

return array (
  'registers per row' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'register' => 10,
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'a',
      ),
      1 => 
      array (
        '@self' => 
        array (
          'register' => 404,
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'c',
      ),
      2 => 
      array (
        '@self' => 
        array (
          'register' => 10,
          'uuid' => '99999999-9999-9999-9999-999999999999',
        ),
        'title' => 'd',
      ),
      3 => 
      array (
        'title' => 'e',
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
            '@self' => 
            array (
              'register' => 11,
              'uuid' => '33333333-3333-3333-3333-333333333333',
            ),
            'title' => 'b',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 1,
          'type' => 'BulkSafeguardException',
        ),
        1 => 
        array (
          'object' => 
          array (
            '@self' => 
            array (
              'register' => '',
              'uuid' => '33333333-3333-3333-3333-333333333333',
            ),
            'title' => 'f',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 5,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 1,
        ),
        1 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 5,
        ),
      ),
      'statistics' => 
      array (
        'invalid' => 2,
        'errors' => 2,
      ),
    ),
    'calls' => 
    array (
      0 => 'register find 10',
      1 => 'register find 11',
      2 => 'register find 404',
      3 => 'prefetch [["11111111-1111-1111-1111-111111111111","99999999-9999-9999-9999-999999999999"],10,7,true]',
      4 => 'prefetch [["33333333-3333-3333-3333-333333333333"],11,7,true]',
      5 => 'callerOrganisationsHold 11111111-1111-1111-1111-111111111111',
      6 => 'hasPermission ["update","bob","bob","11111111-1111-1111-1111-111111111111"]',
      7 => 'callerOrganisationsHold 33333333-3333-3333-3333-333333333333',
      8 => 'find ["33333333-3333-3333-3333-333333333333",11,7,false,true,true]',
      9 => 'register find 404',
      10 => 'find ["11111111-1111-1111-1111-111111111111",null,null,true,false,false]',
      11 => 'callerOrganisationsHold 11111111-1111-1111-1111-111111111111',
      12 => 'hasPermission ["update","bob","bob","11111111-1111-1111-1111-111111111111"]',
      13 => 'hasPermission ["create","bob",null,null]',
      14 => 'hasPermission ["create","bob",null,null]',
      15 => 'find ["33333333-3333-3333-3333-333333333333",null,null,true,false,false]',
      16 => 'callerOrganisationsHold 33333333-3333-3333-3333-333333333333',
      17 => 'find ["33333333-3333-3333-3333-333333333333",null,7,false,true,true]',
    ),
    'log' => 
    array (
      0 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":1}',
      1 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":5}',
    ),
  ),
  'one register prefetch fails' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'register' => 10,
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'a',
      ),
      1 => 
      array (
        '@self' => 
        array (
          'register' => 404,
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'c',
      ),
      2 => 
      array (
        '@self' => 
        array (
          'register' => 10,
          'uuid' => '99999999-9999-9999-9999-999999999999',
        ),
        'title' => 'd',
      ),
      3 => 
      array (
        'title' => 'e',
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
            '@self' => 
            array (
              'register' => 11,
              'uuid' => '33333333-3333-3333-3333-333333333333',
            ),
            'title' => 'b',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 1,
          'type' => 'BulkSafeguardException',
        ),
        1 => 
        array (
          'object' => 
          array (
            '@self' => 
            array (
              'register' => '',
              'uuid' => '33333333-3333-3333-3333-333333333333',
            ),
            'title' => 'f',
          ),
          'error' => 'Permission denied for action create on schema schema-7',
          'index' => 5,
          'type' => 'BulkSafeguardException',
        ),
      ),
      'errors' => 
      array (
        0 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 1,
        ),
        1 => 
        array (
          'error' => 'Permission denied for action create on schema schema-7',
          'type' => 'BulkSafeguardException',
          'index' => 5,
        ),
      ),
      'statistics' => 
      array (
        'invalid' => 2,
        'errors' => 2,
      ),
    ),
    'calls' => 
    array (
      0 => 'register find 10',
      1 => 'register find 11',
      2 => 'register find 404',
      3 => 'prefetch [["11111111-1111-1111-1111-111111111111","99999999-9999-9999-9999-999999999999"],10,7,true]',
      4 => 'prefetch [["33333333-3333-3333-3333-333333333333"],11,7,true]',
      5 => 'callerOrganisationsHold 11111111-1111-1111-1111-111111111111',
      6 => 'hasPermission ["update","bob","bob","11111111-1111-1111-1111-111111111111"]',
      7 => 'find ["33333333-3333-3333-3333-333333333333",11,7,true,false,false]',
      8 => 'callerOrganisationsHold 33333333-3333-3333-3333-333333333333',
      9 => 'find ["33333333-3333-3333-3333-333333333333",11,7,false,true,true]',
      10 => 'register find 404',
      11 => 'find ["11111111-1111-1111-1111-111111111111",null,null,true,false,false]',
      12 => 'callerOrganisationsHold 11111111-1111-1111-1111-111111111111',
      13 => 'hasPermission ["update","bob","bob","11111111-1111-1111-1111-111111111111"]',
      14 => 'hasPermission ["create","bob",null,null]',
      15 => 'hasPermission ["create","bob",null,null]',
      16 => 'find ["33333333-3333-3333-3333-333333333333",null,null,true,false,false]',
      17 => 'callerOrganisationsHold 33333333-3333-3333-3333-333333333333',
      18 => 'find ["33333333-3333-3333-3333-333333333333",null,7,false,true,true]',
    ),
    'log' => 
    array (
      0 => 'warning: [SaveObjects] Batch existence prefetch failed; falling back to per-row lookup {"group":"11\\/7","uuids":1,"error":"probe failed"}',
      1 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":1}',
      2 => 'info: [SaveObjects] Wave-12 bulk safeguard rejected row {"reason":"Permission denied for action create on schema schema-7","index":5}',
    ),
  ),
  'administrator without organisations' => 
  array (
    'passed' => 
    array (
      0 => 
      array (
        '@self' => 
        array (
          'register' => 10,
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'a',
      ),
      1 => 
      array (
        '@self' => 
        array (
          'register' => 11,
          'uuid' => '33333333-3333-3333-3333-333333333333',
        ),
        'title' => 'b',
      ),
      2 => 
      array (
        '@self' => 
        array (
          'register' => 404,
          'uuid' => '11111111-1111-1111-1111-111111111111',
        ),
        'title' => 'c',
      ),
      3 => 
      array (
        '@self' => 
        array (
          'register' => 10,
          'uuid' => '99999999-9999-9999-9999-999999999999',
        ),
        'title' => 'd',
      ),
      4 => 
      array (
        'title' => 'e',
      ),
      5 => 
      array (
        '@self' => 
        array (
          'register' => '',
          'uuid' => '33333333-3333-3333-3333-333333333333',
        ),
        'title' => 'f',
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
        'invalid' => 0,
        'errors' => 0,
      ),
    ),
    'calls' => 
    array (
      0 => 'register find 10',
      1 => 'register find 11',
      2 => 'register find 404',
      3 => 'prefetch [["11111111-1111-1111-1111-111111111111","99999999-9999-9999-9999-999999999999"],10,7,true]',
      4 => 'prefetch [["33333333-3333-3333-3333-333333333333"],11,7,true]',
      5 => 'hasPermission ["update","admin","bob","11111111-1111-1111-1111-111111111111"]',
      6 => 'hasPermission ["update","admin","eve","33333333-3333-3333-3333-333333333333"]',
      7 => 'register find 404',
      8 => 'find ["11111111-1111-1111-1111-111111111111",null,null,true,false,false]',
      9 => 'hasPermission ["update","admin","bob","11111111-1111-1111-1111-111111111111"]',
      10 => 'hasPermission ["create","admin",null,null]',
      11 => 'hasPermission ["create","admin",null,null]',
      12 => 'find ["33333333-3333-3333-3333-333333333333",null,null,true,false,false]',
      13 => 'hasPermission ["update","admin","eve","33333333-3333-3333-3333-333333333333"]',
    ),
    'log' => 
    array (
    ),
  ),
);
