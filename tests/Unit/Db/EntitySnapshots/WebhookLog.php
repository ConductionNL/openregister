<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\WebhookLog written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'webhook' => 'integer',
    'eventClass' => 'string',
    'payload' => 'string',
    'url' => 'string',
    'method' => 'string',
    'success' => 'boolean',
    'statusCode' => 'integer',
    'requestBody' => 'string',
    'responseBody' => 'string',
    'errorMessage' => 'string',
    'attempt' => 'integer',
    'nextRetryAt' => 'datetime',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'webhook' => 0,
    'eventClass' => '',
    'payload' => 
    array (
    ),
    'url' => '',
    'method' => 'POST',
    'success' => false,
    'statusCode' => NULL,
    'requestBody' => NULL,
    'responseBody' => NULL,
    'errorMessage' => NULL,
    'attempt' => 1,
    'nextRetryAt' => NULL,
    'created' => 'now',
  ),
  'filled' => 
  array (
    'id' => 2,
    'webhook' => 7,
    'eventClass' => 'eventClass-v',
    'payload' => 
    array (
    ),
    'url' => 'url-v',
    'method' => 'method-v',
    'success' => true,
    'statusCode' => 10,
    'requestBody' => 'requestBody-v',
    'responseBody' => 'responseBody-v',
    'errorMessage' => 'errorMessage-v',
    'attempt' => 7,
    'nextRetryAt' => '2026-01-02T03:04:05+00:00',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'webhook',
    2 => 'eventClass',
    3 => 'payload',
    4 => 'url',
    5 => 'method',
    6 => 'success',
    7 => 'statusCode',
    8 => 'requestBody',
    9 => 'responseBody',
    10 => 'errorMessage',
    11 => 'attempt',
    12 => 'nextRetryAt',
    13 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
