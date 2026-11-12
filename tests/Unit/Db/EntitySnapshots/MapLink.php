<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\MapLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'favoriteId' => 'integer',
    'name' => 'string',
    'category' => 'string',
    'lat' => 'float',
    'lng' => 'float',
    'comment' => 'string',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'favoriteId' => NULL,
    'name' => NULL,
    'category' => NULL,
    'lat' => NULL,
    'lng' => NULL,
    'comment' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'favoriteId' => 10,
    'name' => 'name-v',
    'category' => 'category-v',
    'lat' => 1.5,
    'lng' => 1.5,
    'comment' => 'comment-v',
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'favoriteId',
    5 => 'name',
    6 => 'category',
    7 => 'lat',
    8 => 'lng',
    9 => 'comment',
    10 => 'linkedBy',
    11 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
