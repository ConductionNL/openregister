<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\SchemaRunEntry written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'runId' => 'integer',
    'objectUuid' => 'string',
    'outcome' => 'string',
    'message' => 'string',
    'preVersion' => 'string',
    'postVersion' => 'string',
    'preData' => 'json',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'runId' => NULL,
    'objectUuid' => NULL,
    'outcome' => NULL,
    'message' => NULL,
    'preVersion' => NULL,
    'postVersion' => NULL,
    'preData' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'runId' => 5,
    'objectUuid' => 'objectUuid-v',
    'outcome' => 'outcome-v',
    'message' => 'message-v',
    'preVersion' => 'preVersion-v',
    'postVersion' => 'postVersion-v',
    'preData' => 
    array (
      'k' => 'preData',
    ),
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'runId',
    2 => 'objectUuid',
    3 => 'outcome',
    4 => 'message',
    5 => 'preVersion',
    6 => 'postVersion',
    7 => 'preData',
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
      'runId' => 5,
      'objectUuid' => 'objectUuid-v',
      'outcome' => 'outcome-v',
      'message' => 'message-v',
      'preVersion' => 'preVersion-v',
      'postVersion' => 'postVersion-v',
      'preData' => 
      array (
        'k' => 'preData',
      ),
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'runId',
      2 => 'objectUuid',
      3 => 'outcome',
      4 => 'message',
      5 => 'preVersion',
      6 => 'postVersion',
      7 => 'preData',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 0,
      'runId' => 0,
      'objectUuid' => '',
      'outcome' => '',
      'message' => '',
      'preVersion' => '',
      'postVersion' => '',
      'preData' => NULL,
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'runId',
      2 => 'objectUuid',
      3 => 'outcome',
      4 => 'message',
      5 => 'preVersion',
      6 => 'postVersion',
    ),
  ),
);
