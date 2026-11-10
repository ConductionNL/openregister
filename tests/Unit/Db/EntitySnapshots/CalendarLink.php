<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\CalendarLink written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'objectUuid' => 'string',
    'registerId' => 'integer',
    'schemaId' => 'integer',
    'calendarUri' => 'string',
    'calendarId' => 'integer',
    'eventUid' => 'string',
    'eventUri' => 'string',
    'summary' => 'string',
    'dtstart' => 'datetime',
    'dtend' => 'datetime',
    'location' => 'string',
    'linkedBy' => 'string',
    'linkedAt' => 'datetime',
    'taggedWithXor' => 'boolean',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'uid' => NULL,
    'calendarId' => NULL,
    'calendarUri' => NULL,
    'summary' => '',
    'dtstart' => NULL,
    'dtend' => NULL,
    'location' => NULL,
    'description' => '',
    'attendees' => 
    array (
    ),
    'status' => NULL,
    'objectUuid' => NULL,
    'registerId' => NULL,
    'schemaId' => NULL,
    'linkedBy' => NULL,
    'linkedAt' => NULL,
    'taggedWithXor' => false,
  ),
  'filled' => 
  array (
    'id' => 'eventUri-v',
    'uid' => 'eventUid-v',
    'calendarId' => '10',
    'calendarUri' => 'calendarUri-v',
    'summary' => 'summary-v',
    'dtstart' => '2026-01-02T03:04:05+00:00',
    'dtend' => '2026-01-02T03:04:05+00:00',
    'location' => 'location-v',
    'description' => '',
    'attendees' => 
    array (
    ),
    'status' => NULL,
    'objectUuid' => 'objectUuid-v',
    'registerId' => 10,
    'schemaId' => 8,
    'linkedBy' => 'linkedBy-v',
    'linkedAt' => '2026-01-02T03:04:05+00:00',
    'taggedWithXor' => true,
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'objectUuid',
    2 => 'registerId',
    3 => 'schemaId',
    4 => 'calendarUri',
    5 => 'calendarId',
    6 => 'eventUid',
    7 => 'eventUri',
    8 => 'summary',
    9 => 'dtstart',
    10 => 'dtend',
    11 => 'location',
    12 => 'linkedBy',
    13 => 'linkedAt',
    14 => 'taggedWithXor',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
