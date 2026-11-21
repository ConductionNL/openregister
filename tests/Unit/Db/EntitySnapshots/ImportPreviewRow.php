<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\ImportPreviewRow written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'previewId' => 'integer',
    'sourceRow' => 'integer',
    'decision' => 'string',
    'reason' => 'string',
    'targetUuid' => 'string',
    'candidates' => 'json',
    'payload' => 'json',
    'appliedAt' => 'datetime',
    'created' => 'datetime',
    'source' => 'json',
    'outcome' => 'string',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'previewId' => NULL,
    'row' => 0,
    'decision' => NULL,
    'reason' => NULL,
    'targetUuid' => NULL,
    'candidates' => 
    array (
    ),
    'payload' => 
    array (
    ),
    'appliedAt' => NULL,
    'created' => NULL,
    'source' => 
    array (
    ),
    'outcome' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'previewId' => 9,
    'row' => 9,
    'decision' => 'decision-v',
    'reason' => 'reason-v',
    'targetUuid' => 'targetUuid-v',
    'candidates' => 
    array (
      'k' => 'candidates',
    ),
    'payload' => 
    array (
      'k' => 'payload',
    ),
    'appliedAt' => '2026-01-02T03:04:05+00:00',
    'created' => '2026-01-02T03:04:05+00:00',
    'source' => 
    array (
      'k' => 'source',
    ),
    'outcome' => 'outcome-v',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'previewId',
    2 => 'sourceRow',
    3 => 'decision',
    4 => 'reason',
    5 => 'targetUuid',
    6 => 'candidates',
    7 => 'payload',
    8 => 'appliedAt',
    9 => 'created',
    10 => 'source',
    11 => 'outcome',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
