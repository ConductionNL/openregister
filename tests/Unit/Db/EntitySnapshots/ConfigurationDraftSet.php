<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\ConfigurationDraftSet written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'name' => 'string',
    'description' => 'string',
    'state' => 'string',
    'createdBy' => 'string',
    'approvedBy' => 'string',
    'approvedAt' => 'datetime',
    'deploymentUuid' => 'string',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'name' => NULL,
    'description' => NULL,
    'state' => NULL,
    'createdBy' => NULL,
    'approvedBy' => NULL,
    'approvedAt' => NULL,
    'deploymentUuid' => NULL,
    'created' => NULL,
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'name' => 'name-v',
    'description' => 'description-v',
    'state' => 'state-v',
    'createdBy' => 'createdBy-v',
    'approvedBy' => 'approvedBy-v',
    'approvedAt' => '2026-01-02T03:04:05+00:00',
    'deploymentUuid' => 'deploymentUuid-v',
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'name',
    3 => 'description',
    4 => 'state',
    5 => 'createdBy',
    6 => 'approvedBy',
    7 => 'approvedAt',
    8 => 'deploymentUuid',
    9 => 'created',
    10 => 'updated',
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
      'name' => 'name-v',
      'description' => 'description-v',
      'state' => 'state-v',
      'createdBy' => 'createdBy-v',
      'approvedBy' => 'approvedBy-v',
      'approvedAt' => '2026-01-02T03:04:05+00:00',
      'deploymentUuid' => 'deploymentUuid-v',
      'created' => '2026-01-02T03:04:05+00:00',
      'updated' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'name',
      3 => 'description',
      4 => 'state',
      5 => 'createdBy',
      6 => 'approvedBy',
      7 => 'approvedAt',
      8 => 'deploymentUuid',
      9 => 'created',
      10 => 'updated',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 0,
      'uuid' => '',
      'name' => '',
      'description' => '',
      'state' => '',
      'createdBy' => '',
      'approvedBy' => '',
      'approvedAt' => 'now',
      'deploymentUuid' => '',
      'created' => 'now',
      'updated' => 'now',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'name',
      3 => 'description',
      4 => 'state',
      5 => 'createdBy',
      6 => 'approvedBy',
      7 => 'approvedAt',
      8 => 'deploymentUuid',
      9 => 'created',
      10 => 'updated',
    ),
  ),
);
