<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\TaskSequence written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'templateId' => 'string',
    'templateVersion' => 'integer',
    'templateSnapshot' => 'json',
    'anchorObjectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'chainKey' => 'string',
    'requesterId' => 'string',
    'resolvedTier' => 'json',
    'positionCursor' => 'integer',
    'status' => 'string',
    'outcome' => 'string',
    'runUuid' => 'string',
    'nodeId' => 'string',
    'openedAt' => 'datetime',
    'closedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'templateId' => NULL,
    'templateVersion' => NULL,
    'templateSnapshot' => NULL,
    'anchorObjectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'chainKey' => NULL,
    'requesterId' => NULL,
    'resolvedTier' => NULL,
    'positionCursor' => 1,
    'status' => NULL,
    'outcome' => NULL,
    'runUuid' => NULL,
    'nodeId' => NULL,
    'openedAt' => NULL,
    'closedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'templateId' => 'templateId-v',
    'templateVersion' => 15,
    'templateSnapshot' => 
    array (
      'k' => 'templateSnapshot',
    ),
    'anchorObjectUuid' => 'anchorObjectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'chainKey' => 'chainKey-v',
    'requesterId' => 'requesterId-v',
    'resolvedTier' => 
    array (
      'k' => 'resolvedTier',
    ),
    'positionCursor' => 14,
    'status' => 'status-v',
    'outcome' => 'outcome-v',
    'runUuid' => 'runUuid-v',
    'nodeId' => 'nodeId-v',
    'openedAt' => '2026-01-02T03:04:05+00:00',
    'closedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'templateId',
    3 => 'templateVersion',
    4 => 'templateSnapshot',
    5 => 'anchorObjectUuid',
    6 => 'registerId',
    7 => 'schemaId',
    8 => 'chainKey',
    9 => 'requesterId',
    10 => 'resolvedTier',
    11 => 'positionCursor',
    12 => 'status',
    13 => 'outcome',
    14 => 'runUuid',
    15 => 'nodeId',
    16 => 'openedAt',
    17 => 'closedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
