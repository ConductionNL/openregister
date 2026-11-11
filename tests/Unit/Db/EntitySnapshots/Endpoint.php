<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\Endpoint written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'name' => 'string',
    'description' => 'string',
    'reference' => 'string',
    'version' => 'string',
    'endpoint' => 'string',
    'endpointArray' => 'json',
    'endpointRegex' => 'string',
    'method' => 'string',
    'targetType' => 'string',
    'targetId' => 'string',
    'conditions' => 'json',
    'inputMapping' => 'string',
    'outputMapping' => 'string',
    'rules' => 'json',
    'configurations' => 'json',
    'slug' => 'string',
    'groups' => 'json',
    'organisation' => 'string',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 'throws RuntimeException',
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'name' => 'name-v',
    'description' => 'description-v',
    'reference' => 'reference-v',
    'version' => 'version-v',
    'endpoint' => 'endpoint-v',
    'endpointArray' => 
    array (
      'k' => 'endpointArray',
    ),
    'endpointRegex' => 'endpointRegex-v',
    'method' => 'method-v',
    'targetType' => 'targetType-v',
    'targetId' => 'targetId-v',
    'conditions' => 
    array (
      'k' => 'conditions',
    ),
    'inputMapping' => 'inputMapping-v',
    'outputMapping' => 'outputMapping-v',
    'rules' => 
    array (
      'k' => 'rules',
    ),
    'configurations' => 
    array (
      'k' => 'configurations',
    ),
    'slug' => 'slug-v',
    'groups' => 
    array (
      'k' => 'groups',
    ),
    'organisation' => 'organisation-v',
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'name',
    3 => 'description',
    4 => 'reference',
    5 => 'version',
    6 => 'endpoint',
    7 => 'endpointArray',
    8 => 'endpointRegex',
    9 => 'method',
    10 => 'targetType',
    11 => 'targetId',
    12 => 'conditions',
    13 => 'inputMapping',
    14 => 'outputMapping',
    15 => 'rules',
    16 => 'configurations',
    17 => 'slug',
    18 => 'groups',
    19 => 'organisation',
    20 => 'created',
    21 => 'updated',
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
      'reference' => 'reference-v',
      'version' => 'version-v',
      'endpoint' => 'endpoint-v',
      'endpointArray' => 
      array (
        'k' => 'endpointArray',
      ),
      'endpointRegex' => 'endpointRegex-v',
      'method' => 'method-v',
      'targetType' => 'targetType-v',
      'targetId' => 'targetId-v',
      'conditions' => 
      array (
        'k' => 'conditions',
      ),
      'inputMapping' => 'inputMapping-v',
      'outputMapping' => 'outputMapping-v',
      'rules' => 
      array (
        'k' => 'rules',
      ),
      'configurations' => 
      array (
        'k' => 'configurations',
      ),
      'slug' => 'slug-v',
      'groups' => 
      array (
        'k' => 'groups',
      ),
      'organisation' => 'organisation-v',
      'created' => '2026-01-02T03:04:05+00:00',
      'updated' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'name',
      3 => 'description',
      4 => 'reference',
      5 => 'version',
      6 => 'endpoint',
      7 => 'endpointArray',
      8 => 'endpointRegex',
      9 => 'method',
      10 => 'targetType',
      11 => 'targetId',
      12 => 'conditions',
      13 => 'inputMapping',
      14 => 'outputMapping',
      15 => 'rules',
      16 => 'configurations',
      17 => 'slug',
      18 => 'groups',
      19 => 'organisation',
      20 => 'created',
      21 => 'updated',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 'throws RuntimeException',
    'updated' => 
    array (
      0 => 'id',
      1 => 'uuid',
      2 => 'name',
      3 => 'description',
      4 => 'reference',
      5 => 'version',
      6 => 'endpoint',
      7 => 'endpointRegex',
      8 => 'method',
      9 => 'targetType',
      10 => 'targetId',
      11 => 'inputMapping',
      12 => 'outputMapping',
      13 => 'slug',
      14 => 'organisation',
      15 => 'created',
      16 => 'updated',
    ),
  ),
);
