<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\BookmarkLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'bookmarkId' => 'integer',
    'title' => 'string',
    'url' => 'string',
    'description' => 'string',
    'tags' => 'json',
    'addedAt' => 'datetime',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'bookmarkId' => NULL,
    'title' => NULL,
    'url' => NULL,
    'description' => NULL,
    'tags' => 
    array (
    ),
    'addedAt' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'bookmarkId' => 10,
    'title' => 'title-v',
    'url' => 'url-v',
    'description' => 'description-v',
    'tags' => 
    array (
      'k' => 'tags',
    ),
    'addedAt' => '2026-01-02T03:04:05+00:00',
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'bookmarkId',
    5 => 'title',
    6 => 'url',
    7 => 'description',
    8 => 'tags',
    9 => 'addedAt',
    10 => 'linkedBy',
    11 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
