<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\SyncRecord written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'sourceId' => 'integer',
    'executionId' => 'string',
    'externalId' => 'string',
    'status' => 'string',
    'objectUuid' => 'string',
    'contentHash' => 'string',
    'rawData' => 'json',
    'errorMessage' => 'string',
    'attempts' => 'integer',
    'organisation' => 'string',
    'updated' => 'datetime',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'sourceId' => NULL,
    'executionId' => NULL,
    'externalId' => NULL,
    'status' => NULL,
    'objectUuid' => NULL,
    'contentHash' => NULL,
    'rawData' => NULL,
    'errorMessage' => NULL,
    'attempts' => NULL,
    'organisation' => NULL,
    'updated' => NULL,
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'sourceId' => 8,
    'executionId' => 'executionId-v',
    'externalId' => 'externalId-v',
    'status' => 'status-v',
    'objectUuid' => 'objectUuid-v',
    'contentHash' => 'contentHash-v',
    'rawData' => 
    array (
      'k' => 'rawData',
    ),
    'errorMessage' => 'errorMessage-v',
    'attempts' => 8,
    'organisation' => 'organisation-v',
    'updated' => '2026-01-02T03:04:05+00:00',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'sourceId',
    3 => 'executionId',
    4 => 'externalId',
    5 => 'status',
    6 => 'objectUuid',
    7 => 'contentHash',
    8 => 'rawData',
    9 => 'errorMessage',
    10 => 'attempts',
    11 => 'organisation',
    12 => 'updated',
    13 => 'created',
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
      'sourceId' => 8,
      'executionId' => 'executionId-v',
      'externalId' => 'externalId-v',
      'status' => 'status-v',
      'objectUuid' => 'objectUuid-v',
      'contentHash' => 'contentHash-v',
      'rawData' => 
      array (
        'k' => 'rawData',
      ),
      'errorMessage' => 'errorMessage-v',
      'attempts' => 8,
      'organisation' => 'organisation-v',
      'updated' => '2026-01-02T03:04:05+00:00',
      'created' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'sourceId',
      3 => 'executionId',
      4 => 'externalId',
      5 => 'status',
      6 => 'objectUuid',
      7 => 'contentHash',
      8 => 'rawData',
      9 => 'errorMessage',
      10 => 'attempts',
      11 => 'organisation',
      12 => 'updated',
      13 => 'created',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 0,
      'uuid' => '',
      'sourceId' => 0,
      'executionId' => '',
      'externalId' => '',
      'status' => '',
      'objectUuid' => '',
      'contentHash' => '',
      'rawData' => NULL,
      'errorMessage' => '',
      'attempts' => 0,
      'organisation' => '',
      'updated' => 'now',
      'created' => 'now',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'sourceId',
      3 => 'executionId',
      4 => 'externalId',
      5 => 'status',
      6 => 'objectUuid',
      7 => 'contentHash',
      8 => 'errorMessage',
      9 => 'attempts',
      10 => 'organisation',
      11 => 'updated',
      12 => 'created',
    ),
  ),
);
