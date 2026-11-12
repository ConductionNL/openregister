<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\TimeTrackerLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'entryType' => 'string',
    'clientId' => 'string',
    'taskId' => 'string',
    'timeId' => 'string',
    'name' => 'string',
    'duration' => 'integer',
    'billable' => 'boolean',
    'startedAt' => 'datetime',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'entryType' => NULL,
    'clientId' => NULL,
    'taskId' => NULL,
    'timeId' => NULL,
    'name' => NULL,
    'duration' => NULL,
    'billable' => NULL,
    'startedAt' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'entryType' => 'entryType-v',
    'clientId' => 'clientId-v',
    'taskId' => 'taskId-v',
    'timeId' => 'timeId-v',
    'name' => 'name-v',
    'duration' => 8,
    'billable' => true,
    'startedAt' => '2026-01-02T03:04:05+00:00',
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'entryType',
    5 => 'clientId',
    6 => 'taskId',
    7 => 'timeId',
    8 => 'name',
    9 => 'duration',
    10 => 'billable',
    11 => 'startedAt',
    12 => 'linkedBy',
    13 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
