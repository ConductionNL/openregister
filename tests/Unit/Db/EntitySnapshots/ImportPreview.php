<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\ImportPreview written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'packSlug' => 'string',
    'policy' => 'string',
    'matchKey' => 'json',
    'sourceName' => 'string',
    'sourceFormat' => 'string',
    'sourceHash' => 'string',
    'sourcePath' => 'string',
    'state' => 'string',
    'total' => 'integer',
    'processed' => 'integer',
    'toCreate' => 'integer',
    'toUpdate' => 'integer',
    'toSkip' => 'integer',
    'toRefuse' => 'integer',
    'applied' => 'integer',
    'failed' => 'integer',
    'report' => 'json',
    'createdBy' => 'string',
    'created' => 'datetime',
    'updated' => 'datetime',
    'causeRun' => 'string',
    'rowsPrunedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'packSlug' => NULL,
    'policy' => NULL,
    'matchKey' => 
    array (
    ),
    'sourceName' => NULL,
    'sourceFormat' => NULL,
    'sourceHash' => NULL,
    'state' => NULL,
    'total' => 0,
    'processed' => 0,
    'counts' => 
    array (
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'refused' => 0,
    ),
    'applied' => 0,
    'failed' => 0,
    'report' => 
    array (
    ),
    'createdBy' => NULL,
    'created' => NULL,
    'updated' => NULL,
    'causeRun' => NULL,
    'rowsPruned' => false,
    'rowsPrunedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'packSlug' => 'packSlug-v',
    'policy' => 'policy-v',
    'matchKey' => 
    array (
      'k' => 'matchKey',
    ),
    'sourceName' => 'sourceName-v',
    'sourceFormat' => 'sourceFormat-v',
    'sourceHash' => 'sourceHash-v',
    'state' => 'state-v',
    'total' => 5,
    'processed' => 9,
    'counts' => 
    array (
      'created' => 8,
      'updated' => 8,
      'skipped' => 6,
      'refused' => 8,
    ),
    'applied' => 7,
    'failed' => 6,
    'report' => 
    array (
      'k' => 'report',
    ),
    'createdBy' => 'createdBy-v',
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
    'causeRun' => 'causeRun-v',
    'rowsPruned' => true,
    'rowsPrunedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'packSlug',
    5 => 'policy',
    6 => 'matchKey',
    7 => 'sourceName',
    8 => 'sourceFormat',
    9 => 'sourceHash',
    10 => 'sourcePath',
    11 => 'state',
    12 => 'total',
    13 => 'processed',
    14 => 'toCreate',
    15 => 'toUpdate',
    16 => 'toSkip',
    17 => 'toRefuse',
    18 => 'applied',
    19 => 'failed',
    20 => 'report',
    21 => 'createdBy',
    22 => 'created',
    23 => 'updated',
    24 => 'causeRun',
    25 => 'rowsPrunedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
