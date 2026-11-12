<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\PortalTaskDelivery written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'taskUuid' => 'string',
    'partyReference' => 'string',
    'channel' => 'string',
    'kind' => 'string',
    'state' => 'string',
    'message' => 'json',
    'error' => 'string',
    'requestedAt' => 'datetime',
    'deliveredAt' => 'datetime',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => NULL,
    'taskUuid' => NULL,
    'partyReference' => NULL,
    'channel' => NULL,
    'kind' => NULL,
    'state' => NULL,
    'message' => NULL,
    'error' => NULL,
    'requestedAt' => NULL,
    'deliveredAt' => NULL,
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'taskUuid' => 'taskUuid-v',
    'partyReference' => 'partyReference-v',
    'channel' => 'channel-v',
    'kind' => 'kind-v',
    'state' => 'state-v',
    'message' => 
    array (
      'k' => 'message',
    ),
    'error' => 'error-v',
    'requestedAt' => '2026-01-02T03:04:05+00:00',
    'deliveredAt' => '2026-01-02T03:04:05+00:00',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'taskUuid',
    3 => 'partyReference',
    4 => 'channel',
    5 => 'kind',
    6 => 'state',
    7 => 'message',
    8 => 'error',
    9 => 'requestedAt',
    10 => 'deliveredAt',
    11 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
