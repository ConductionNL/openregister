<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Snapshot of OCA\OpenRegister\Db\FlowVersion written from the code before its refactor; see EntitySnapshotGoldenTest.

return array (
  'fieldTypes' => 
  array (
    'id' => 'integer',
    'flowUuid' => 'string',
    'version' => 'integer',
    'status' => 'string',
    'definitionHash' => 'string',
    'owner' => 'string',
    'organisation' => 'string',
    'publishedAt' => 'datetime',
    'publishedBy' => 'string',
    'deprecatedAt' => 'datetime',
    'semver' => 'string',
    'semverSource' => 'string',
    'created' => 'datetime',
  ),
  'empty' => 
  array (
    'id' => NULL,
    'flowUuid' => NULL,
    'version' => NULL,
    'semver' => NULL,
    'semverSource' => NULL,
    'status' => NULL,
    'definitionHash' => NULL,
    'owner' => NULL,
    'organisation' => NULL,
    'publishedAt' => NULL,
    'publishedBy' => NULL,
    'deprecatedAt' => NULL,
    'created' => NULL,
  ),
  'filled' => 
  array (
    'id' => 2,
    'flowUuid' => 'flowUuid-v',
    'version' => 7,
    'semver' => 'semver-v',
    'semverSource' => 'semverSource-v',
    'status' => 'status-v',
    'definitionHash' => 'definitionHash-v',
    'owner' => 'owner-v',
    'organisation' => 'organisation-v',
    'publishedAt' => '2026-01-02T03:04:05+00:00',
    'publishedBy' => 'publishedBy-v',
    'deprecatedAt' => '2026-01-02T03:04:05+00:00',
    'created' => '2026-01-02T03:04:05+00:00',
  ),
  'filledUpdated' => 
  array (
    0 => 'id',
    1 => 'flowUuid',
    2 => 'version',
    3 => 'status',
    4 => 'definitionHash',
    5 => 'owner',
    6 => 'organisation',
    7 => 'publishedAt',
    8 => 'publishedBy',
    9 => 'deprecatedAt',
    10 => 'semver',
    11 => 'semverSource',
    12 => 'created',
  ),
  'refused' => 
  array (
  ),
  'hydrated' => 'no hydrate',
  'hydratedBlank' => 'no hydrate',
);
