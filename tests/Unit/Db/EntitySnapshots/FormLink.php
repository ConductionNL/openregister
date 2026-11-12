<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FormLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'formId' => 'integer',
    'formHash' => 'string',
    'submissionId' => 'integer',
    'title' => 'string',
    'status' => 'string',
    'expiresAt' => 'datetime',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'formId' => NULL,
    'formHash' => NULL,
    'submissionId' => NULL,
    'title' => NULL,
    'status' => NULL,
    'expiresAt' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'formId' => 6,
    'formHash' => 'formHash-v',
    'submissionId' => 12,
    'title' => 'title-v',
    'status' => 'status-v',
    'expiresAt' => '2026-01-02T03:04:05+00:00',
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'formId',
    5 => 'formHash',
    6 => 'submissionId',
    7 => 'title',
    8 => 'status',
    9 => 'expiresAt',
    10 => 'linkedBy',
    11 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
