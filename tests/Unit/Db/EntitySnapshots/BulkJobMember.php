<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\BulkJobMember written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'jobId' => 'integer',
    'objectUuid' => 'string',
    'outcome' => 'string',
    'reason' => 'string',
    'schemaVersion' => 'string',
    'priorValues' => 'json',
    'appliedValues' => 'json',
    'addedAtCommit' => 'boolean',
    'appliedAt' => 'datetime',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'jobId' => NULL,
    'objectUuid' => NULL,
    'outcome' => NULL,
    'reason' => NULL,
    'schemaVersion' => NULL,
    'priorValues' => NULL,
    'appliedValues' => NULL,
    'addedAtCommit' => false,
    'appliedAt' => NULL,
    'created' => NULL,
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'jobId' => 5,
    'objectUuid' => 'objectUuid-v',
    'outcome' => 'outcome-v',
    'reason' => 'reason-v',
    'schemaVersion' => 'schemaVersion-v',
    'priorValues' => 
    array (
      'k' => 'priorValues',
    ),
    'appliedValues' => 
    array (
      'k' => 'appliedValues',
    ),
    'addedAtCommit' => true,
    'appliedAt' => '2026-01-02T03:04:05+00:00',
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'jobId',
    2 => 'objectUuid',
    3 => 'outcome',
    4 => 'reason',
    5 => 'schemaVersion',
    6 => 'priorValues',
    7 => 'appliedValues',
    8 => 'addedAtCommit',
    9 => 'appliedAt',
    10 => 'created',
    11 => 'updated',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 2,
      'jobId' => 5,
      'objectUuid' => 'objectUuid-v',
      'outcome' => 'outcome-v',
      'reason' => 'reason-v',
      'schemaVersion' => 'schemaVersion-v',
      'priorValues' => 
      array (
        'k' => 'priorValues',
      ),
      'appliedValues' => 
      array (
        'k' => 'appliedValues',
      ),
      'addedAtCommit' => true,
      'appliedAt' => '2026-01-02T03:04:05+00:00',
      'created' => '2026-01-02T03:04:05+00:00',
      'updated' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'jobId',
      2 => 'objectUuid',
      3 => 'outcome',
      4 => 'reason',
      5 => 'schemaVersion',
      6 => 'priorValues',
      7 => 'appliedValues',
      8 => 'addedAtCommit',
      9 => 'appliedAt',
      10 => 'created',
      11 => 'updated',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 0,
      'jobId' => 0,
      'objectUuid' => '',
      'outcome' => '',
      'reason' => '',
      'schemaVersion' => '',
      'priorValues' => 
      array (
      ),
      'appliedValues' => 
      array (
      ),
      'addedAtCommit' => false,
      'appliedAt' => 'now',
      'created' => 'now',
      'updated' => 'now',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'jobId',
      2 => 'objectUuid',
      3 => 'outcome',
      4 => 'reason',
      5 => 'schemaVersion',
      6 => 'priorValues',
      7 => 'appliedValues',
      8 => 'addedAtCommit',
      9 => 'appliedAt',
      10 => 'created',
      11 => 'updated',
    ),
  ),
);
