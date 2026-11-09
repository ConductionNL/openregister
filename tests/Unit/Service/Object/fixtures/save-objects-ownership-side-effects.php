<?php

/**
 * Recorded outcomes for SaveObjectsOwnershipAndSideEffectsGoldenTest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

return array (
  'ownership' => 
  array (
    'session user overrides a client owner' => 
    array (
      'self' => 
      array (
        'owner' => 'bob',
        'organisation' => 'active-org',
      ),
      'calls' => 
      array (
        0 => 'getOrganisationForNewEntity',
      ),
    ),
    'no session user keeps a client owner' => 
    array (
      'self' => 
      array (
        'owner' => 'bob',
        'organisation' => 'active-org',
      ),
      'calls' => 
      array (
        0 => 'getOrganisationForNewEntity',
      ),
    ),
    'no session user and an empty owner' => 
    array (
      'self' => 
      array (
        'owner' => 'bob',
        'organisation' => 'active-org',
      ),
      'calls' => 
      array (
        0 => 'getOrganisationForNewEntity',
      ),
    ),
    'no session user and no owner' => 
    array (
      'self' => 
      array (
        'owner' => 'bob',
        'organisation' => 'active-org',
      ),
      'calls' => 
      array (
        0 => 'getOrganisationForNewEntity',
      ),
    ),
    'no organisation asked' => 
    array (
      'self' => 
      array (
        'owner' => 'bob',
        'organisation' => 'active-org',
      ),
      'calls' => 
      array (
        0 => 'getOrganisationForNewEntity',
      ),
    ),
    'empty organisation asked' => 
    array (
      'self' => 
      array (
        'organisation' => 'active-org',
        'owner' => 'bob',
      ),
      'calls' => 
      array (
        0 => 'getOrganisationForNewEntity',
      ),
    ),
    'foreign organisation for a non-member' => 
    array (
      'self' => 
      array (
        'organisation' => 'active-org',
        'owner' => 'bob',
      ),
      'calls' => 
      array (
        0 => 'isAdmin bob',
        1 => 'hasAccessToOrganisation theirs',
        2 => 'getOrganisationForNewEntity',
      ),
    ),
    'own organisation for a member' => 
    array (
      'self' => 
      array (
        'organisation' => 'mine',
        'owner' => 'bob',
      ),
      'calls' => 
      array (
        0 => 'isAdmin bob',
        1 => 'hasAccessToOrganisation mine',
      ),
    ),
    'foreign organisation for an administrator' => 
    array (
      'self' => 
      array (
        'organisation' => 'theirs',
        'owner' => 'admin',
      ),
      'calls' => 
      array (
        0 => 'isAdmin admin',
      ),
    ),
    'foreign organisation without group manager' => 
    array (
      'self' => 
      array (
        'organisation' => 'active-org',
        'owner' => 'admin',
      ),
      'calls' => 
      array (
        0 => 'hasAccessToOrganisation theirs',
        1 => 'getOrganisationForNewEntity',
      ),
    ),
    'foreign organisation without session user' => 
    array (
      'self' => 
      array (
        'organisation' => 'active-org',
        'owner' => 'bob',
      ),
      'calls' => 
      array (
        0 => 'isAdmin bob',
        1 => 'hasAccessToOrganisation theirs',
        2 => 'getOrganisationForNewEntity',
      ),
    ),
    'own organisation without session user' => 
    array (
      'self' => 
      array (
        'organisation' => 'mine',
        'owner' => 'bob',
      ),
      'calls' => 
      array (
        0 => 'isAdmin bob',
        1 => 'hasAccessToOrganisation mine',
      ),
    ),
  ),
  'sideEffects' => 
  array (
    'audit and events' => 
    array (
      0 => 'audit [["create",null,"c1"],["update","u1","u1"],["update","u1","u1"]]',
      1 => 'dispatch ObjectCreatedEvent',
      2 => 'dispatch ObjectUpdatedEvent',
      3 => 'dispatch ObjectUpdatedEvent',
    ),
    'audit only' => 
    array (
      0 => 'audit [["create",null,"c1"],["update","u1","u1"],["update","u1","u1"]]',
    ),
    'events only' => 
    array (
      0 => 'dispatch ObjectCreatedEvent',
      1 => 'dispatch ObjectUpdatedEvent',
      2 => 'dispatch ObjectUpdatedEvent',
    ),
    'neither' => 
    array (
    ),
    'no audit mapper' => 
    array (
      0 => 'dispatch ObjectCreatedEvent',
      1 => 'dispatch ObjectUpdatedEvent',
      2 => 'dispatch ObjectUpdatedEvent',
    ),
    'no dispatcher' => 
    array (
      0 => 'audit [["create",null,"c1"],["update","u1","u1"],["update","u1","u1"]]',
    ),
    'system operation' => 
    array (
      0 => 'audit [["create",null,"c1"],["update","u1","u1"],["update","u1","u1"]]',
    ),
    'failing audit and events' => 
    array (
      0 => 'audit [["create",null,"c1"],["update","u1","u1"],["update","u1","u1"]]',
      1 => 'log warning: [SaveObjects] Failed to write batched audit trail for bulk chunk {"error":"audit broke","entries":3}',
      2 => 'dispatch ObjectCreatedEvent',
      3 => 'log warning: [SaveObjects] Failed to dispatch ObjectCreatedEvent for bulk object {"error":"listener broke","uuid":"c1"}',
      4 => 'dispatch ObjectUpdatedEvent',
      5 => 'log warning: [SaveObjects] Failed to dispatch ObjectUpdatedEvent for bulk object {"error":"listener broke","uuid":"u1"}',
      6 => 'dispatch ObjectUpdatedEvent',
      7 => 'log warning: [SaveObjects] Failed to dispatch ObjectUpdatedEvent for bulk object {"error":"listener broke","uuid":"u1"}',
    ),
    'nothing to emit' => 
    array (
    ),
  ),
);
