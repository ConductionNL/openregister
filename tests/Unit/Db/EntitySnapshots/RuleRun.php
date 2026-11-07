<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\RuleRun written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'ruleId' => 'string',
    'schemaSlug' => 'string',
    'registerSlug' => 'string',
    'objectUuid' => 'string',
    'verdict' => 'string',
    'operand' => 'string',
    'operandValue' => 'string',
    'message' => 'string',
    'actor' => 'string',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'ruleId' => NULL,
    'schema' => NULL,
    'register' => NULL,
    'objectUuid' => NULL,
    'verdict' => NULL,
    'operand' => NULL,
    'operandValue' => NULL,
    'message' => NULL,
    'actor' => NULL,
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'ruleId' => 'ruleId-v',
    'schema' => 'schemaSlug-v',
    'register' => 'registerSlug-v',
    'objectUuid' => 'objectUuid-v',
    'verdict' => 'verdict-v',
    'operand' => 'operand-v',
    'operandValue' => 'operandValue-v',
    'message' => 'message-v',
    'actor' => 'actor-v',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'ruleId',
    2 => 'schemaSlug',
    3 => 'registerSlug',
    4 => 'objectUuid',
    5 => 'verdict',
    6 => 'operand',
    7 => 'operandValue',
    8 => 'message',
    9 => 'actor',
    10 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
