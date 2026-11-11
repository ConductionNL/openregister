<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\BulkJob written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'action' => 'string',
    'parameters' => 'json',
    'selectionType' => 'string',
    'selection' => 'json',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'justification' => 'string',
    'state' => 'string',
    'total' => 'integer',
    'processed' => 'integer',
    'applied' => 'integer',
    'skipped' => 'integer',
    'refused' => 'integer',
    'failed' => 'integer',
    'cursor' => 'integer',
    'report' => 'json',
    'startedBy' => 'string',
    'reversalWindow' => 'integer',
    'reversibleUntil' => 'datetime',
    'reversesJobId' => 'integer',
    'reversedByJobId' => 'integer',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'action' => NULL,
    'parameters' => 
    array (
    ),
    'selectionType' => NULL,
    'selection' => 
    array (
    ),
    'registerId' => NULL,
    'schemaId' => NULL,
    'justification' => NULL,
    'state' => NULL,
    'total' => 0,
    'processed' => 0,
    'counts' => 
    array (
      'applied' => 0,
      'skipped' => 0,
      'refused' => 0,
      'failed' => 0,
    ),
    'cursor' => 0,
    'report' => 
    array (
    ),
    'startedBy' => NULL,
    'reversible' => false,
    'reversalWindow' => NULL,
    'reversibleUntil' => NULL,
    'reversesJobId' => NULL,
    'reversedByJobId' => NULL,
    'created' => NULL,
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'action' => 'action-v',
    'parameters' => 
    array (
      'k' => 'parameters',
    ),
    'selectionType' => 'selectionType-v',
    'selection' => 
    array (
      'k' => 'selection',
    ),
    'registerId' => 10,
    'schemaId' => 8,
    'justification' => 'justification-v',
    'state' => 'state-v',
    'total' => 5,
    'processed' => 9,
    'counts' => 
    array (
      'applied' => 7,
      'skipped' => 7,
      'refused' => 7,
      'failed' => 6,
    ),
    'cursor' => 6,
    'report' => 
    array (
      'k' => 'report',
    ),
    'startedBy' => 'startedBy-v',
    'reversible' => true,
    'reversalWindow' => 14,
    'reversibleUntil' => '2026-01-02T03:04:05+00:00',
    'reversesJobId' => 13,
    'reversedByJobId' => 15,
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'action',
    3 => 'parameters',
    4 => 'selectionType',
    5 => 'selection',
    6 => 'registerId',
    7 => 'schemaId',
    8 => 'justification',
    9 => 'state',
    10 => 'total',
    11 => 'processed',
    12 => 'applied',
    13 => 'skipped',
    14 => 'refused',
    15 => 'failed',
    16 => 'cursor',
    17 => 'report',
    18 => 'startedBy',
    19 => 'reversalWindow',
    20 => 'reversibleUntil',
    21 => 'reversesJobId',
    22 => 'reversedByJobId',
    23 => 'created',
    24 => 'updated',
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
      'action' => 'action-v',
      'parameters' => 
      array (
        'k' => 'parameters',
      ),
      'selectionType' => 'selectionType-v',
      'selection' => 
      array (
        'k' => 'selection',
      ),
      'registerId' => 10,
      'schemaId' => 8,
      'justification' => 'justification-v',
      'state' => 'state-v',
      'total' => 5,
      'processed' => 9,
      'counts' => 
      array (
        'applied' => 7,
        'skipped' => 7,
        'refused' => 7,
        'failed' => 6,
      ),
      'cursor' => 6,
      'report' => 
      array (
        'k' => 'report',
      ),
      'startedBy' => 'startedBy-v',
      'reversible' => true,
      'reversalWindow' => 14,
      'reversibleUntil' => '2026-01-02T03:04:05+00:00',
      'reversesJobId' => 13,
      'reversedByJobId' => 15,
      'created' => '2026-01-02T03:04:05+00:00',
      'updated' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'action',
      3 => 'parameters',
      4 => 'selectionType',
      5 => 'selection',
      6 => 'registerId',
      7 => 'schemaId',
      8 => 'justification',
      9 => 'state',
      10 => 'total',
      11 => 'processed',
      12 => 'applied',
      13 => 'skipped',
      14 => 'refused',
      15 => 'failed',
      16 => 'cursor',
      17 => 'report',
      18 => 'startedBy',
      19 => 'reversalWindow',
      20 => 'reversibleUntil',
      21 => 'reversesJobId',
      22 => 'reversedByJobId',
      23 => 'created',
      24 => 'updated',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 0,
      'uuid' => '',
      'action' => '',
      'parameters' => 
      array (
      ),
      'selectionType' => '',
      'selection' => 
      array (
      ),
      'registerId' => 0,
      'schemaId' => 0,
      'justification' => '',
      'state' => '',
      'total' => 0,
      'processed' => 0,
      'counts' => 
      array (
        'applied' => 0,
        'skipped' => 0,
        'refused' => 0,
        'failed' => 0,
      ),
      'cursor' => 0,
      'report' => 
      array (
      ),
      'startedBy' => '',
      'reversible' => false,
      'reversalWindow' => 0,
      'reversibleUntil' => 'now',
      'reversesJobId' => 0,
      'reversedByJobId' => 0,
      'created' => 'now',
      'updated' => 'now',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'action',
      3 => 'selectionType',
      4 => 'registerId',
      5 => 'schemaId',
      6 => 'justification',
      7 => 'state',
      8 => 'total',
      9 => 'processed',
      10 => 'applied',
      11 => 'skipped',
      12 => 'refused',
      13 => 'failed',
      14 => 'cursor',
      15 => 'startedBy',
      16 => 'reversalWindow',
      17 => 'reversibleUntil',
      18 => 'reversesJobId',
      19 => 'reversedByJobId',
      20 => 'created',
      21 => 'updated',
    ),
  ),
);
