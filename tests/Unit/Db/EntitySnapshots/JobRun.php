<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\JobRun written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'jobClass' => 'string',
    'argumentDigest' => 'string',
    'started' => 'datetime',
    'ended' => 'datetime',
    'durationMs' => 'integer',
    'outcome' => 'string',
    'message' => 'string',
    'details' => 'string',
    'cause' => 'string',
    'actor' => 'string',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'job' => NULL,
    'name' => '',
    'argumentDigest' => NULL,
    'started' => NULL,
    'ended' => NULL,
    'durationMs' => NULL,
    'outcome' => NULL,
    'message' => NULL,
    'details' => NULL,
    'cause' => NULL,
    'actor' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'job' => 'jobClass-v',
    'name' => 'jobClass-v',
    'argumentDigest' => 'argumentDigest-v',
    'started' => '2026-01-02T03:04:05+00:00',
    'ended' => '2026-01-02T03:04:05+00:00',
    'durationMs' => 10,
    'outcome' => 'outcome-v',
    'message' => 'message-v',
    'details' => NULL,
    'cause' => 'cause-v',
    'actor' => 'actor-v',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'jobClass',
    2 => 'argumentDigest',
    3 => 'started',
    4 => 'ended',
    5 => 'durationMs',
    6 => 'outcome',
    7 => 'message',
    8 => 'details',
    9 => 'cause',
    10 => 'actor',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
