<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FlowClaim written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'runUuid' => 'string',
    'place' => 'string',
    'owner' => 'string',
    'streamId' => 'string',
    'transition' => 'string',
    'claimedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'runUuid' => NULL,
    'place' => NULL,
    'owner' => NULL,
    'streamId' => NULL,
    'transition' => NULL,
    'claimedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'runUuid' => 'runUuid-v',
    'place' => 'place-v',
    'owner' => 'owner-v',
    'streamId' => 'streamId-v',
    'transition' => 'transition-v',
    'claimedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'runUuid',
    2 => 'place',
    3 => 'owner',
    4 => 'streamId',
    5 => 'transition',
    6 => 'claimedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
