<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\StateHistory written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'register' => 'string',
    'schema' => 'string',
    'property' => 'string',
    'value' => 'string',
    'enteredAt' => 'datetime',
    'leftAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'register' => NULL,
    'schema' => NULL,
    'property' => NULL,
    'value' => NULL,
    'enteredAt' => NULL,
    'leftAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'register' => 'register-v',
    'schema' => 'schema-v',
    'property' => 'property-v',
    'value' => 'value-v',
    'enteredAt' => '2026-01-02T03:04:05+00:00',
    'leftAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'register',
    3 => 'schema',
    4 => 'property',
    5 => 'value',
    6 => 'enteredAt',
    7 => 'leftAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
