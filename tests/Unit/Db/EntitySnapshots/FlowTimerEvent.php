<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FlowTimerEvent written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'timerUuid' => 'string',
    'type' => 'string',
    'actor' => 'string',
    'reason' => 'string',
    'priorFireAt' => 'datetime',
    'newFireAt' => 'datetime',
    'daysImpact' => 'float',
    'basis' => 'string',
    'unrolledAt' => 'datetime',
    'rolledBy' => 'string',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'timerUuid' => NULL,
    'type' => NULL,
    'actor' => NULL,
    'reason' => NULL,
    'priorFireAt' => NULL,
    'newFireAt' => NULL,
    'daysImpact' => NULL,
    'basis' => NULL,
    'unrolledAt' => NULL,
    'rolledBy' => NULL,
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'timerUuid' => 'timerUuid-v',
    'type' => 'type-v',
    'actor' => 'actor-v',
    'reason' => 'reason-v',
    'priorFireAt' => '2026-01-02T03:04:05+00:00',
    'newFireAt' => '2026-01-02T03:04:05+00:00',
    'daysImpact' => 1.5,
    'basis' => 'basis-v',
    'unrolledAt' => '2026-01-02T03:04:05+00:00',
    'rolledBy' => 'rolledBy-v',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'timerUuid',
    2 => 'type',
    3 => 'actor',
    4 => 'reason',
    5 => 'priorFireAt',
    6 => 'newFireAt',
    7 => 'daysImpact',
    8 => 'basis',
    9 => 'unrolledAt',
    10 => 'rolledBy',
    11 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
