<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\ObjectReadState written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'userId' => 'string',
    'objectUuid' => 'string',
    'register' => 'string',
    'schema' => 'string',
    'lastSeenAt' => 'datetime',
    'subSeen' => 'json',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'userId' => NULL,
    'objectUuid' => NULL,
    'register' => NULL,
    'schema' => NULL,
    'lastSeenAt' => NULL,
    'subSeen' => 
    array (
    ),
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'userId' => 'userId-v',
    'objectUuid' => 'objectUuid-v',
    'register' => 'register-v',
    'schema' => 'schema-v',
    'lastSeenAt' => '2026-01-02T03:04:05+00:00',
    'subSeen' => 
    array (
      'k' => 'subSeen',
    ),
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'userId',
    2 => 'objectUuid',
    3 => 'register',
    4 => 'schema',
    5 => 'lastSeenAt',
    6 => 'subSeen',
    7 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
