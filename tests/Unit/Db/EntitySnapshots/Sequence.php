<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\Sequence written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'scopeKey' => 'string',
    'nextValue' => 'integer',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'scopeKey' => NULL,
    'nextValue' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'registerId' => 10,
    'schemaId' => 8,
    'scopeKey' => 'scopeKey-v',
    'nextValue' => 9,
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'registerId',
    2 => 'schemaId',
    3 => 'scopeKey',
    4 => 'nextValue',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
