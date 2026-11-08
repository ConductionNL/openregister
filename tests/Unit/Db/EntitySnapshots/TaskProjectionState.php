<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\TaskProjectionState written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'taskUuid' => 'string',
    'surface' => 'string',
    'assignee' => 'string',
    'calendarId' => 'integer',
    'objectUri' => 'string',
    'renderedHash' => 'string',
    'renderedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'taskUuid' => NULL,
    'surface' => NULL,
    'assignee' => NULL,
    'calendarId' => NULL,
    'objectUri' => NULL,
    'renderedHash' => NULL,
    'renderedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'taskUuid' => 'taskUuid-v',
    'surface' => 'surface-v',
    'assignee' => 'assignee-v',
    'calendarId' => 10,
    'objectUri' => 'objectUri-v',
    'renderedHash' => 'renderedHash-v',
    'renderedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'taskUuid',
    2 => 'surface',
    3 => 'assignee',
    4 => 'calendarId',
    5 => 'objectUri',
    6 => 'renderedHash',
    7 => 'renderedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
