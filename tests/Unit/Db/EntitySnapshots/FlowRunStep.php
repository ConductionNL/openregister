<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FlowRunStep written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'runUuid' => 'string',
    'flowId' => 'string',
    'nodeId' => 'string',
    'nodeType' => 'string',
    'sequence' => 'integer',
    'streamId' => 'string',
    'ordinalPath' => 'string',
    'status' => 'string',
    'started' => 'datetime',
    'finished' => 'datetime',
    'durationMs' => 'integer',
    'output' => 'json',
    'error' => 'string',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'runUuid' => NULL,
    'flowId' => NULL,
    'nodeId' => NULL,
    'nodeType' => NULL,
    'sequence' => 0,
    'streamId' => NULL,
    'ordinalPath' => NULL,
    'status' => NULL,
    'started' => NULL,
    'finished' => NULL,
    'durationMs' => NULL,
    'output' => NULL,
    'error' => NULL,
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'runUuid' => 'runUuid-v',
    'flowId' => 'flowId-v',
    'nodeId' => 'nodeId-v',
    'nodeType' => 'nodeType-v',
    'sequence' => 8,
    'streamId' => 'streamId-v',
    'ordinalPath' => 'ordinalPath-v',
    'status' => 'status-v',
    'started' => '2026-01-02T03:04:05+00:00',
    'finished' => '2026-01-02T03:04:05+00:00',
    'durationMs' => 10,
    'output' => 
    array (
      'k' => 'output',
    ),
    'error' => 'error-v',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'runUuid',
    2 => 'flowId',
    3 => 'nodeId',
    4 => 'nodeType',
    5 => 'sequence',
    6 => 'streamId',
    7 => 'ordinalPath',
    8 => 'status',
    9 => 'started',
    10 => 'finished',
    11 => 'durationMs',
    12 => 'output',
    13 => 'error',
    14 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
