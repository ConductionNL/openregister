<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\DeckLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'boardId' => 'integer',
    'stackId' => 'integer',
    'cardId' => 'integer',
    'cardTitle' => 'string',
    'dueDate' => 'datetime',
    'labels' => 'string',
    'assignees' => 'string',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'boardId' => NULL,
    'stackId' => NULL,
    'cardId' => NULL,
    'cardTitle' => NULL,
    'dueDate' => NULL,
    'labels' => 
    array (
    ),
    'assignees' => 
    array (
    ),
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'boardId' => 7,
    'stackId' => 7,
    'cardId' => 6,
    'cardTitle' => 'cardTitle-v',
    'dueDate' => '2026-01-02T03:04:05+00:00',
    'labels' => 
    array (
    ),
    'assignees' => 
    array (
    ),
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'boardId',
    5 => 'stackId',
    6 => 'cardId',
    7 => 'cardTitle',
    8 => 'dueDate',
    9 => 'labels',
    10 => 'assignees',
    11 => 'linkedBy',
    12 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
