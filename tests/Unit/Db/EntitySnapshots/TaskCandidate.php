<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\TaskCandidate written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'taskId' => 'integer',
    'kind' => 'string',
    'ref' => 'string',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'taskId' => NULL,
    'kind' => NULL,
    'ref' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'taskId' => 6,
    'kind' => 'kind-v',
    'ref' => 'ref-v',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'taskId',
    2 => 'kind',
    3 => 'ref',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
