<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\TaskRelation written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'taskId' => 'integer',
    'role' => 'string',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'taskId' => NULL,
    'role' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'taskId' => 6,
    'role' => 'role-v',
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'taskId',
    2 => 'role',
    3 => 'objectUuid',
    4 => 'registerId',
    5 => 'schemaId',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
