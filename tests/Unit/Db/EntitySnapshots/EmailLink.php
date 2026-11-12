<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\EmailLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'mailAccountId' => 'integer',
    'mailMessageId' => 'integer',
    'mailMessageUid' => 'string',
    'subject' => 'string',
    'sender' => 'string',
    'senderName' => 'string',
    'mailDate' => 'datetime',
    'metadata' => 'string',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
    'rfcMessageId' => 'string',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'mailAccountId' => NULL,
    'mailMessageId' => NULL,
    'mailMessageUid' => NULL,
    'subject' => NULL,
    'sender' => NULL,
    'senderName' => NULL,
    'mailDate' => NULL,
    'metadata' => NULL,
    'details' => 
    array (
      'direction' => NULL,
      'recipientName' => NULL,
      'attachmentCount' => NULL,
      'linkedVia' => NULL,
      'archivedAs' => NULL,
      'mailboxName' => NULL,
    ),
    'linkedBy' => NULL,
    'linkedAt' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'mailAccountId' => 13,
    'mailMessageId' => 13,
    'mailMessageUid' => 'mailMessageUid-v',
    'subject' => 'subject-v',
    'sender' => 'sender-v',
    'senderName' => 'senderName-v',
    'mailDate' => '2026-01-02T03:04:05+00:00',
    'metadata' => 'metadata-v',
    'details' => 
    array (
      'direction' => NULL,
      'recipientName' => NULL,
      'attachmentCount' => NULL,
      'linkedVia' => NULL,
      'archivedAs' => NULL,
      'mailboxName' => NULL,
    ),
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'mailAccountId',
    5 => 'mailMessageId',
    6 => 'mailMessageUid',
    7 => 'subject',
    8 => 'sender',
    9 => 'senderName',
    10 => 'mailDate',
    11 => 'metadata',
    12 => 'linkedBy',
    13 => 'linkedAt',
    14 => 'rfcMessageId',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
