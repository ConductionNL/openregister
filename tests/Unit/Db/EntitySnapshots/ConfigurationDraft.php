<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\ConfigurationDraft written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'setUuid' => 'string',
    'layer' => 'string',
    'layerRef' => 'string',
    'configKey' => 'string',
    'baseValue' => 'json',
    'draftValue' => 'json',
    'basePresent' => 'boolean',
    'removes' => 'boolean',
    'createdBy' => 'string',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'setUuid' => NULL,
    'layer' => NULL,
    'layerRef' => NULL,
    'key' => NULL,
    'base' => NULL,
    'basePresent' => false,
    'value' => NULL,
    'removes' => false,
    'createdBy' => NULL,
    'created' => NULL,
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'setUuid' => 'setUuid-v',
    'layer' => 'layer-v',
    'layerRef' => 'layerRef-v',
    'key' => 'configKey-v',
    'base' => NULL,
    'basePresent' => true,
    'value' => NULL,
    'removes' => true,
    'createdBy' => 'createdBy-v',
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'setUuid',
    3 => 'layer',
    4 => 'layerRef',
    5 => 'configKey',
    6 => 'baseValue',
    7 => 'draftValue',
    8 => 'basePresent',
    9 => 'removes',
    10 => 'createdBy',
    11 => 'created',
    12 => 'updated',
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
      'setUuid' => 'setUuid-v',
      'layer' => 'layer-v',
      'layerRef' => 'layerRef-v',
      'key' => 'configKey-v',
      'base' => NULL,
      'basePresent' => true,
      'value' => NULL,
      'removes' => true,
      'createdBy' => 'createdBy-v',
      'created' => '2026-01-02T03:04:05+00:00',
      'updated' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'setUuid',
      3 => 'layer',
      4 => 'layerRef',
      5 => 'configKey',
      6 => 'baseValue',
      7 => 'draftValue',
      8 => 'basePresent',
      9 => 'removes',
      10 => 'createdBy',
      11 => 'created',
      12 => 'updated',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 0,
      'uuid' => '',
      'setUuid' => '',
      'layer' => '',
      'layerRef' => '',
      'key' => '',
      'base' => NULL,
      'basePresent' => false,
      'value' => NULL,
      'removes' => false,
      'createdBy' => '',
      'created' => 'now',
      'updated' => 'now',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'setUuid',
      3 => 'layer',
      4 => 'layerRef',
      5 => 'configKey',
      6 => 'baseValue',
      7 => 'draftValue',
      8 => 'basePresent',
      9 => 'removes',
      10 => 'createdBy',
      11 => 'created',
      12 => 'updated',
    ),
  ),
);
