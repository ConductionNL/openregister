<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\PollLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'pollId' => 'integer',
    'pollTitle' => 'string',
    'pollType' => 'string',
    'deadline' => 'datetime',
    'voterCount' => 'integer',
    'optionCount' => 'integer',
    'closed' => 'boolean',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'pollId' => NULL,
    'pollTitle' => NULL,
    'pollType' => NULL,
    'deadline' => NULL,
    'voterCount' => NULL,
    'optionCount' => NULL,
    'closed' => false,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'pollId' => 6,
    'pollTitle' => 'pollTitle-v',
    'pollType' => 'pollType-v',
    'deadline' => '2026-01-02T03:04:05+00:00',
    'voterCount' => 10,
    'optionCount' => 11,
    'closed' => true,
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'pollId',
    5 => 'pollTitle',
    6 => 'pollType',
    7 => 'deadline',
    8 => 'voterCount',
    9 => 'optionCount',
    10 => 'closed',
    11 => 'linkedBy',
    12 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
