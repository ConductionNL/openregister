<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\PhotoLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'albumId' => 'integer',
    'albumName' => 'string',
    'coverPhotoUrl' => 'string',
    'photoCount' => 'integer',
    'lastEdited' => 'datetime',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'albumId' => NULL,
    'albumName' => NULL,
    'coverPhotoUrl' => NULL,
    'photoCount' => NULL,
    'lastEdited' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'albumId' => 7,
    'albumName' => 'albumName-v',
    'coverPhotoUrl' => 'coverPhotoUrl-v',
    'photoCount' => 10,
    'lastEdited' => '2026-01-02T03:04:05+00:00',
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'albumId',
    5 => 'albumName',
    6 => 'coverPhotoUrl',
    7 => 'photoCount',
    8 => 'lastEdited',
    9 => 'linkedBy',
    10 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
