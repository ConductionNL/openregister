<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\XwikiLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'pageReference' => 'string',
    'space' => 'string',
    'title' => 'string',
    'url' => 'string',
    'cachedAt' => 'datetime',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'pageReference' => NULL,
    'space' => NULL,
    'title' => NULL,
    'url' => NULL,
    'cachedAt' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'pageReference' => 'pageReference-v',
    'space' => 'space-v',
    'title' => 'title-v',
    'url' => 'url-v',
    'cachedAt' => '2026-01-02T03:04:05+00:00',
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'pageReference',
    5 => 'space',
    6 => 'title',
    7 => 'url',
    8 => 'cachedAt',
    9 => 'linkedBy',
    10 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
