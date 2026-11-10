<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\ConfigurationDeployment written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'name' => 'string',
    'setUuid' => 'string',
    'author' => 'string',
    'approver' => 'string',
    'deployedBy' => 'string',
    'deployedAt' => 'datetime',
    'restoresUuid' => 'string',
    'changes' => 'json',
    'changeCount' => 'integer',
    'state' => 'string',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'name' => NULL,
    'setUuid' => NULL,
    'author' => NULL,
    'approver' => NULL,
    'deployedBy' => NULL,
    'deployedAt' => NULL,
    'restores' => NULL,
    'isRollback' => false,
    'changes' => 
    array (
    ),
    'changeCount' => 0,
    'state' => NULL,
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'name' => 'name-v',
    'setUuid' => 'setUuid-v',
    'author' => 'author-v',
    'approver' => 'approver-v',
    'deployedBy' => 'deployedBy-v',
    'deployedAt' => '2026-01-02T03:04:05+00:00',
    'restores' => 'restoresUuid-v',
    'isRollback' => true,
    'changes' => 
    array (
      'k' => 'changes',
    ),
    'changeCount' => 11,
    'state' => 'state-v',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'name',
    3 => 'setUuid',
    4 => 'author',
    5 => 'approver',
    6 => 'deployedBy',
    7 => 'deployedAt',
    8 => 'restoresUuid',
    9 => 'changes',
    10 => 'changeCount',
    11 => 'state',
    12 => 'created',
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
      'setUuid' => 'setUuid-v',
      'author' => 'author-v',
      'approver' => 'approver-v',
      'deployedBy' => 'deployedBy-v',
      'deployedAt' => '2026-01-02T03:04:05+00:00',
      'restores' => 'restoresUuid-v',
      'isRollback' => true,
      'changes' => 
      array (
        'k' => 'changes',
      ),
      'changeCount' => 11,
      'state' => 'state-v',
      'created' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'name',
      3 => 'setUuid',
      4 => 'author',
      5 => 'approver',
      6 => 'deployedBy',
      7 => 'deployedAt',
      8 => 'restoresUuid',
      9 => 'changes',
      10 => 'changeCount',
      11 => 'state',
      12 => 'created',
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
      'setUuid' => '',
      'author' => '',
      'approver' => '',
      'deployedBy' => '',
      'deployedAt' => 'now',
      'restores' => '',
      'isRollback' => true,
      'changes' => 
      array (
      ),
      'changeCount' => 0,
      'state' => '',
      'created' => 'now',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'name',
      3 => 'setUuid',
      4 => 'author',
      5 => 'approver',
      6 => 'deployedBy',
      7 => 'deployedAt',
      8 => 'restoresUuid',
      9 => 'changes',
      10 => 'changeCount',
      11 => 'state',
      12 => 'created',
    ),
  ),
);
