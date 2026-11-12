<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FlowState written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'flowId' => 'string',
    'state' => 'json',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'flowId' => NULL,
    'state' => 
    array (
    ),
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'flowId' => 'flowId-v',
    'state' => 
    array (
      'k' => 'state',
    ),
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'flowId',
    2 => 'state',
    3 => 'updated',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
