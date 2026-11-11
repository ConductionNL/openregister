<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\AnalyticsLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'reportId' => 'integer',
    'reportTitle' => 'string',
    'reportType' => 'string',
    'subheader' => 'string',
    'createdAt' => 'datetime',
    'modifiedAt' => 'datetime',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'reportId' => NULL,
    'reportTitle' => NULL,
    'reportType' => NULL,
    'subheader' => NULL,
    'createdAt' => NULL,
    'modifiedAt' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'reportId' => 8,
    'reportTitle' => 'reportTitle-v',
    'reportType' => 'reportType-v',
    'subheader' => 'subheader-v',
    'createdAt' => '2026-01-02T03:04:05+00:00',
    'modifiedAt' => '2026-01-02T03:04:05+00:00',
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'reportId',
    5 => 'reportTitle',
    6 => 'reportType',
    7 => 'subheader',
    8 => 'createdAt',
    9 => 'modifiedAt',
    10 => 'linkedBy',
    11 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
