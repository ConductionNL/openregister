<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\TalkLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'roomToken' => 'string',
    'roomId' => 'integer',
    'roomName' => 'string',
    'roomType' => 'integer',
    'subtitle' => 'string',
    'participantCount' => 'integer',
    'lastMessageData' => 'string',
    'lastActivity' => 'datetime',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'roomToken' => NULL,
    'roomId' => NULL,
    'roomName' => NULL,
    'roomType' => NULL,
    'subtitle' => NULL,
    'participantCount' => NULL,
    'lastMessage' => NULL,
    'lastActivity' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
    'url' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'roomToken' => 'roomToken-v',
    'roomId' => 6,
    'roomName' => 'roomName-v',
    'roomType' => 8,
    'subtitle' => 'subtitle-v',
    'participantCount' => 16,
    'lastMessage' => NULL,
    'lastActivity' => '2026-01-02T03:04:05+00:00',
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
    'url' => '/index.php/call/roomToken-v',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'roomToken',
    5 => 'roomId',
    6 => 'roomName',
    7 => 'roomType',
    8 => 'subtitle',
    9 => 'participantCount',
    10 => 'lastMessageData',
    11 => 'lastActivity',
    12 => 'linkedBy',
    13 => 'linkedAt',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
