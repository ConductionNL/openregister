<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\RecordTemplate written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'name' => 'string',
    'description' => 'string',
    'register' => 'string',
    'schema' => 'string',
    'templateValues' => 'json',
    'owner' => 'string',
    'isPublic' => 'boolean',
    'sharedWith' => 'json',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'name' => NULL,
    'description' => NULL,
    'register' => NULL,
    'schema' => NULL,
    'values' => 
    array (
    ),
    'owner' => NULL,
    'isPublic' => false,
    'sharedWith' => 
    array (
    ),
    '@self' => 
    array (
      'access' => NULL,
    ),
    'created' => NULL,
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'name' => 'name-v',
    'description' => 'description-v',
    'register' => 'register-v',
    'schema' => 'schema-v',
    'values' => 
    array (
      'k' => 'templateValues',
    ),
    'owner' => 'owner-v',
    'isPublic' => true,
    'sharedWith' => 
    array (
      'k' => 'sharedWith',
    ),
    '@self' => 
    array (
      'access' => NULL,
    ),
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'name',
    3 => 'description',
    4 => 'register',
    5 => 'schema',
    6 => 'templateValues',
    7 => 'owner',
    8 => 'isPublic',
    9 => 'sharedWith',
    10 => 'created',
    11 => 'updated',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
