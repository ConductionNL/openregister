<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FlowDefinition written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'hash' => 'string',
    'flowUuid' => 'string',
    'definition' => 'string',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'hash' => NULL,
    'flowUuid' => NULL,
    'definition' => 
    array (
    ),
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'hash' => 'hash-v',
    'flowUuid' => 'flowUuid-v',
    'definition' => 
    array (
    ),
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'hash',
    2 => 'flowUuid',
    3 => 'definition',
    4 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
