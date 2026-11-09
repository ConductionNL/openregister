<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\SchemaRun written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'schemaId' => 'integer',
    'registerId' => 'integer',
    'type' => 'string',
    'state' => 'string',
    'proposedDefinition' => 'json',
    'plan' => 'json',
    'options' => 'json',
    'processed' => 'integer',
    'total' => 'integer',
    'cursor' => 'integer',
    'report' => 'json',
    'startedBy' => 'string',
    'rolledBackFrom' => 'integer',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'schemaId' => NULL,
    'registerId' => NULL,
    'type' => NULL,
    'state' => NULL,
    'proposedDefinition' => NULL,
    'plan' => 
    array (
    ),
    'options' => 
    array (
    ),
    'processed' => 0,
    'total' => 0,
    'cursor' => 0,
    'report' => 
    array (
    ),
    'startedBy' => NULL,
    'rolledBackFrom' => NULL,
    'created' => NULL,
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'schemaId' => 8,
    'registerId' => 10,
    'type' => 'type-v',
    'state' => 'state-v',
    'proposedDefinition' => 
    array (
      'k' => 'proposedDefinition',
    ),
    'plan' => 
    array (
      'k' => 'plan',
    ),
    'options' => 
    array (
      'k' => 'options',
    ),
    'processed' => 9,
    'total' => 5,
    'cursor' => 6,
    'report' => 
    array (
      'k' => 'report',
    ),
    'startedBy' => 'startedBy-v',
    'rolledBackFrom' => 14,
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'schemaId',
    3 => 'registerId',
    4 => 'type',
    5 => 'state',
    6 => 'proposedDefinition',
    7 => 'plan',
    8 => 'options',
    9 => 'processed',
    10 => 'total',
    11 => 'cursor',
    12 => 'report',
    13 => 'startedBy',
    14 => 'rolledBackFrom',
    15 => 'created',
    16 => 'updated',
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
      'uuid' => 'uuid-v',
      'schemaId' => 8,
      'registerId' => 10,
      'type' => 'type-v',
      'state' => 'state-v',
      'proposedDefinition' => 
      array (
        'k' => 'proposedDefinition',
      ),
      'plan' => 
      array (
        'k' => 'plan',
      ),
      'options' => 
      array (
        'k' => 'options',
      ),
      'processed' => 9,
      'total' => 5,
      'cursor' => 6,
      'report' => 
      array (
        'k' => 'report',
      ),
      'startedBy' => 'startedBy-v',
      'rolledBackFrom' => 14,
      'created' => '2026-01-02T03:04:05+00:00',
      'updated' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'schemaId',
      3 => 'registerId',
      4 => 'type',
      5 => 'state',
      6 => 'proposedDefinition',
      7 => 'plan',
      8 => 'options',
      9 => 'processed',
      10 => 'total',
      11 => 'cursor',
      12 => 'report',
      13 => 'startedBy',
      14 => 'rolledBackFrom',
      15 => 'created',
      16 => 'updated',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 0,
      'uuid' => '',
      'schemaId' => 0,
      'registerId' => 0,
      'type' => '',
      'state' => '',
      'proposedDefinition' => NULL,
      'plan' => 
      array (
      ),
      'options' => 
      array (
      ),
      'processed' => 0,
      'total' => 0,
      'cursor' => 0,
      'report' => 
      array (
      ),
      'startedBy' => '',
      'rolledBackFrom' => 0,
      'created' => 'now',
      'updated' => 'now',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'schemaId',
      3 => 'registerId',
      4 => 'type',
      5 => 'state',
      6 => 'processed',
      7 => 'total',
      8 => 'cursor',
      9 => 'startedBy',
      10 => 'rolledBackFrom',
      11 => 'created',
      12 => 'updated',
    ),
  ),
);
