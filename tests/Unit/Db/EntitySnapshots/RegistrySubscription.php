<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\RegistrySubscription written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'register' => 'string',
    'schema' => 'string',
    'registry' => 'string',
    'identityValue' => 'string',
    'state' => 'string',
    'lastUpdateAt' => 'datetime',
    'lastUpdateSource' => 'string',
    'refusalReason' => 'string',
    'createdAt' => 'datetime',
    'updatedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'register' => NULL,
    'schema' => NULL,
    'registry' => NULL,
    'identityValue' => NULL,
    'state' => 'none',
    'lastUpdateAt' => NULL,
    'lastUpdateSource' => NULL,
    'refusalReason' => NULL,
    'createdAt' => NULL,
    'updatedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'register' => 'register-v',
    'schema' => 'schema-v',
    'registry' => 'registry-v',
    'identityValue' => 'identityValue-v',
    'state' => 'state-v',
    'lastUpdateAt' => '2026-01-02T03:04:05+00:00',
    'lastUpdateSource' => 'lastUpdateSource-v',
    'refusalReason' => 'refusalReason-v',
    'createdAt' => '2026-01-02T03:04:05+00:00',
    'updatedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'register',
    3 => 'schema',
    4 => 'registry',
    5 => 'identityValue',
    6 => 'state',
    7 => 'lastUpdateAt',
    8 => 'lastUpdateSource',
    9 => 'refusalReason',
    10 => 'createdAt',
    11 => 'updatedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
