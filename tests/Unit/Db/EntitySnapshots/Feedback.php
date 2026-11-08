<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\Feedback written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'uuid' => 'string',
    'messageId' => 'integer',
    'conversationId' => 'integer',
    'agentId' => 'integer',
    'userId' => 'string',
    'organisation' => 'string',
    'type' => 'string',
    'comment' => 'string',
    'created' => 'datetime',
    'updated' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uuid' => '',
    'messageId' => 0,
    'conversationId' => 0,
    'agentId' => 0,
    'userId' => '',
    'organisation' => NULL,
    'type' => '',
    'comment' => NULL,
    'created' => NULL,
    'updated' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'uuid' => 'uuid-v',
    'messageId' => 9,
    'conversationId' => 14,
    'agentId' => 7,
    'userId' => 'userId-v',
    'organisation' => 'organisation-v',
    'type' => 'type-v',
    'comment' => 'comment-v',
    'created' => '2026-01-02T03:04:05+00:00',
    'updated' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'uuid',
    2 => 'messageId',
    3 => 'conversationId',
    4 => 'agentId',
    5 => 'userId',
    6 => 'organisation',
    7 => 'type',
    8 => 'comment',
    9 => 'created',
    10 => 'updated',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
