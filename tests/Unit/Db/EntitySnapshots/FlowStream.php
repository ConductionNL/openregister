<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FlowStream written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'runUuid' => 'string',
    'streamId' => 'string',
    'ordinalPath' => 'string',
    'parentStreamId' => 'string',
    'place' => 'string',
    'status' => 'string',
    'resumeAt' => 'datetime',
    'nextSequence' => 'integer',
    'error' => 'string',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'runUuid' => NULL,
    'streamId' => NULL,
    'ordinalPath' => NULL,
    'parentStreamId' => NULL,
    'place' => NULL,
    'status' => NULL,
    'resumeAt' => NULL,
    'nextSequence' => 1,
    'error' => NULL,
    'created' => NULL,
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'runUuid' => 'runUuid-v',
    'streamId' => 'streamId-v',
    'ordinalPath' => 'ordinalPath-v',
    'parentStreamId' => 'parentStreamId-v',
    'place' => 'place-v',
    'status' => 'status-v',
    'resumeAt' => '2026-01-02T03:04:05+00:00',
    'nextSequence' => 12,
    'error' => 'error-v',
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'runUuid',
    2 => 'streamId',
    3 => 'ordinalPath',
    4 => 'parentStreamId',
    5 => 'place',
    6 => 'status',
    7 => 'resumeAt',
    8 => 'nextSequence',
    9 => 'error',
    10 => 'created',
    11 => 'updated',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
