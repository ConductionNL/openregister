<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\HandoffQueueEntry written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'sourceObjectUuid' => 'string',
    'sourceRegister' => 'integer',
    'sourceSchema' => 'integer',
    'handoffId' => 'string',
    'targetKind' => 'string',
    'requestingUser' => 'string',
    'correlationId' => 'string',
    'mappingHash' => 'string',
    'status' => 'string',
    'attempt' => 'integer',
    'lastError' => 'string',
    'created' => 'datetime',
    'lastAttemptAt' => 'datetime',
    'executedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'sourceObjectUuid' => '',
    'sourceRegister' => 0,
    'sourceSchema' => 0,
    'handoffId' => '',
    'targetKind' => '',
    'requestingUser' => '',
    'correlationId' => '',
    'status' => 'parked',
    'attempt' => 0,
    'lastError' => NULL,
    'created' => 'now',
    'lastAttemptAt' => NULL,
    'executedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'sourceObjectUuid' => 'sourceObjectUuid-v',
    'sourceRegister' => 14,
    'sourceSchema' => 12,
    'handoffId' => 'handoffId-v',
    'targetKind' => 'targetKind-v',
    'requestingUser' => 'requestingUser-v',
    'correlationId' => 'correlationId-v',
    'status' => 'status-v',
    'attempt' => 7,
    'lastError' => 'lastError-v',
    'created' => '2026-01-02T03:04:05+00:00',
    'lastAttemptAt' => '2026-01-02T03:04:05+00:00',
    'executedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'sourceObjectUuid',
    2 => 'sourceRegister',
    3 => 'sourceSchema',
    4 => 'handoffId',
    5 => 'targetKind',
    6 => 'requestingUser',
    7 => 'correlationId',
    8 => 'mappingHash',
    9 => 'status',
    10 => 'attempt',
    11 => 'lastError',
    12 => 'created',
    13 => 'lastAttemptAt',
    14 => 'executedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
