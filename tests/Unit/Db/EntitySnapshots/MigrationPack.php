<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\MigrationPack written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'packSlug' => 'string',
    'name' => 'string',
    'sourceFormat' => 'string',
    'version' => 'string',
    'definition' => 'string',
    'builtin' => 'boolean',
    'owner' => 'string',
    'createdAt' => 'datetime',
    'updatedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'packSlug' => NULL,
    'name' => NULL,
    'sourceFormat' => NULL,
    'version' => NULL,
    'definition' => 
    array (
    ),
    'builtin' => NULL,
    'owner' => NULL,
    'createdAt' => NULL,
    'updatedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'packSlug' => 'packSlug-v',
    'name' => 'name-v',
    'sourceFormat' => 'sourceFormat-v',
    'version' => 'version-v',
    'definition' => 
    array (
    ),
    'builtin' => true,
    'owner' => 'owner-v',
    'createdAt' => '2026-01-02T03:04:05+00:00',
    'updatedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'packSlug',
    2 => 'name',
    3 => 'sourceFormat',
    4 => 'version',
    5 => 'definition',
    6 => 'builtin',
    7 => 'owner',
    8 => 'createdAt',
    9 => 'updatedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
