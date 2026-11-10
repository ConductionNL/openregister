<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FlowTimerFire written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'timerUuid' => 'string',
    'rungKey' => 'string',
    'firedAt' => 'datetime',
    'transitionAction' => 'string',
    'recipientRoles' => 'json',
    'priority' => 'string',
    'inherited' => 'boolean',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'timerUuid' => NULL,
    'rungKey' => NULL,
    'firedAt' => NULL,
    'transitionAction' => NULL,
    'recipientRoles' => NULL,
    'priority' => NULL,
    'inherited' => false,
  ),
  'filled' => 
  array (
    'id' => 2,
    'timerUuid' => 'timerUuid-v',
    'rungKey' => 'rungKey-v',
    'firedAt' => '2026-01-02T03:04:05+00:00',
    'transitionAction' => 'transitionAction-v',
    'recipientRoles' => 
    array (
      'k' => 'recipientRoles',
    ),
    'priority' => 'priority-v',
    'inherited' => true,
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'timerUuid',
    2 => 'rungKey',
    3 => 'firedAt',
    4 => 'transitionAction',
    5 => 'recipientRoles',
    6 => 'priority',
    7 => 'inherited',
    8 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
