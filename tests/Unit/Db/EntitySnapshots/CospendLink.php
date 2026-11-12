<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\CospendLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'entryType' => 'string',
    'projectId' => 'string',
    'billId' => 'integer',
    'name' => 'string',
    'amount' => 'float',
    'currency' => 'string',
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
    'projectId' => NULL,
    'billId' => NULL,
    'name' => NULL,
    'amount' => NULL,
    'currency' => NULL,
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
    'projectId' => 'projectId-v',
    'billId' => 6,
    'name' => 'name-v',
    'amount' => 1.5,
    'currency' => 'currency-v',
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
    5 => 'projectId',
    6 => 'billId',
    7 => 'name',
    8 => 'amount',
    9 => 'currency',
    10 => 'linkedBy',
    11 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
