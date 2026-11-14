<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\SchemaChangelog written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'schemaId' => 'integer',
    'version' => 'string',
    'classification' => 'string',
    'changes' => 'json',
    'actor' => 'string',
    'acknowledgedBy' => 'string',
    'acknowledgedAt' => 'datetime',
    'noticeSentTo' => 'integer',
    'noticeSentAt' => 'datetime',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'schemaId' => NULL,
    'version' => NULL,
    'classification' => NULL,
    'changes' => 
    array (
    ),
    'actor' => NULL,
    'acknowledgedBy' => NULL,
    'acknowledgedAt' => NULL,
    'noticeSentTo' => NULL,
    'noticeSentAt' => NULL,
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'schemaId' => 8,
    'version' => 'version-v',
    'classification' => 'classification-v',
    'changes' => 
    array (
      'k' => 'changes',
    ),
    'actor' => 'actor-v',
    'acknowledgedBy' => 'acknowledgedBy-v',
    'acknowledgedAt' => '2026-01-02T03:04:05+00:00',
    'noticeSentTo' => 12,
    'noticeSentAt' => '2026-01-02T03:04:05+00:00',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'schemaId',
    2 => 'version',
    3 => 'classification',
    4 => 'changes',
    5 => 'actor',
    6 => 'acknowledgedBy',
    7 => 'acknowledgedAt',
    8 => 'noticeSentTo',
    9 => 'noticeSentAt',
    10 => 'created',
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
      'schemaId' => 8,
      'version' => 'version-v',
      'classification' => 'classification-v',
      'changes' => 
      array (
        'k' => 'changes',
      ),
      'actor' => 'actor-v',
      'acknowledgedBy' => 'acknowledgedBy-v',
      'acknowledgedAt' => '2026-01-02T03:04:05+00:00',
      'noticeSentTo' => 12,
      'noticeSentAt' => '2026-01-02T03:04:05+00:00',
      'created' => '2026-01-02T03:04:05+00:00',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'schemaId',
      2 => 'version',
      3 => 'classification',
      4 => 'changes',
      5 => 'actor',
      6 => 'acknowledgedBy',
      7 => 'acknowledgedAt',
      8 => 'noticeSentTo',
      9 => 'noticeSentAt',
      10 => 'created',
    ),
  ),
  'hydratedBlank' => 
  array (
    'returnsSelf' => true,
    'form' => 
    array (
      'id' => 0,
      'schemaId' => 0,
      'version' => '',
      'classification' => '',
      'changes' => 
      array (
      ),
      'actor' => '',
      'acknowledgedBy' => '',
      'acknowledgedAt' => 'now',
      'noticeSentTo' => 0,
      'noticeSentAt' => 'now',
      'created' => 'now',
    ),
    'updated' => 
    array (
      0 => 'id',
      1 => 'schemaId',
      2 => 'version',
      3 => 'classification',
      4 => 'actor',
      5 => 'acknowledgedBy',
      6 => 'acknowledgedAt',
      7 => 'noticeSentTo',
      8 => 'noticeSentAt',
      9 => 'created',
    ),
  ),
);
