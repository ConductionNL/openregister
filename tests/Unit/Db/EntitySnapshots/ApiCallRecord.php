<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\ApiCallRecord written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'principal' => 'string',
    'route' => 'string',
    'method' => 'string',
    'apiVersion' => 'string',
    'callCount' => 'integer',
    'firstSeen' => 'datetime',
    'lastSeen' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'principal' => 'anonymous',
    'route' => '',
    'method' => 'GET',
    'version' => '1',
    'calls' => 0,
    'firstSeen' => NULL,
    'lastSeen' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'principal' => 'principal-v',
    'route' => 'route-v',
    'method' => 'method-v',
    'version' => 'apiVersion-v',
    'calls' => 9,
    'firstSeen' => '2026-01-02T03:04:05+00:00',
    'lastSeen' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'principal',
    2 => 'route',
    3 => 'method',
    4 => 'apiVersion',
    5 => 'callCount',
    6 => 'firstSeen',
    7 => 'lastSeen',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
