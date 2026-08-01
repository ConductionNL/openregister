<?php

/**
 * Tests for SharePrincipalDeriver.
 *
 * The derived lists are what the RBAC predicates match, so every case here is an
 * access-control case: a principal that wrongly appears grants access, and one
 * that wrongly disappears revokes it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\Sharing;

use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use PHPUnit\Framework\TestCase;

class SharePrincipalDeriverTest extends TestCase
{

    private SharePrincipalDeriver $deriver;

    protected function setUp(): void
    {
        $this->deriver = new SharePrincipalDeriver();
    }//end setUp()

    // ── deriving ──

    public function testSplitsUsersAndGroups(): void
    {
        $result = $this->deriver->apply(
            [
                'sharedWith' => [
                    ['type' => 'user', 'id' => 'alice', 'permission' => 'run'],
                    ['type' => 'group', 'id' => 'finance', 'permission' => 'read'],
                    ['type' => 'user', 'id' => 'bob', 'permission' => 'read'],
                ],
            ]
        );

        $this->assertSame(['alice', 'bob'], $result['sharedUsers']);
        $this->assertSame(['finance'], $result['sharedGroups']);
    }

    /**
     * The derived lists are OUTPUTS. Accepting a client-supplied value would let
     * a caller grant themselves access without appearing in `sharedWith` at all.
     */
    public function testDiscardsClientSuppliedDerivedLists(): void
    {
        $result = $this->deriver->apply(
            [
                'sharedWith'   => [['type' => 'user', 'id' => 'alice']],
                'sharedUsers'  => ['mallory'],
                'sharedGroups' => ['admin'],
            ]
        );

        $this->assertSame(['alice'], $result['sharedUsers']);
        $this->assertSame([], $result['sharedGroups']);
    }

    /**
     * Clearing the share list must clear the principals, not leave stale ones
     * behind still granting access.
     */
    public function testClearingTheShareListClearsTheDerivedLists(): void
    {
        $result = $this->deriver->apply(['sharedWith' => [], 'sharedUsers' => ['alice']]);

        $this->assertSame([], $result['sharedUsers']);
        $this->assertSame([], $result['sharedGroups']);
    }

    public function testAbsentShareListYieldsEmptyLists(): void
    {
        $result = $this->deriver->apply(['name' => 'no shares here']);

        $this->assertSame([], $result['sharedUsers']);
        $this->assertSame([], $result['sharedGroups']);
    }

    /**
     * Duplicates must not survive: jsonb containment would still match, but a
     * repeated principal in the haystack is a sign the list was built by
     * appending rather than deriving.
     */
    public function testDeduplicates(): void
    {
        $result = $this->deriver->apply(
            [
                'sharedWith' => [
                    ['type' => 'user', 'id' => 'alice'],
                    ['type' => 'user', 'id' => 'alice'],
                ],
            ]
        );

        $this->assertSame(['alice'], $result['sharedUsers']);
    }

    /**
     * The derived lists must serialise as JSON ARRAYS. Gappy integer keys would
     * encode as a JSON object, which jsonb containment does not match — the
     * share would silently stop working on the list path only.
     */
    public function testDerivedListsAreSequentialArrays(): void
    {
        $result = $this->deriver->apply(
            [
                'sharedWith' => [
                    ['type' => 'user', 'id' => 'alice'],
                    ['type' => 'group', 'id' => 'finance'],
                    ['type' => 'user', 'id' => 'alice'],
                ],
            ]
        );

        $this->assertSame('["alice"]', json_encode($result['sharedUsers']));
        $this->assertSame('["finance"]', json_encode($result['sharedGroups']));
    }

    // ── validation: every dropped entry is one that cannot widen access ──

    public function testDropsUnknownPrincipalType(): void
    {
        $result = $this->deriver->apply(['sharedWith' => [['type' => 'everyone', 'id' => 'x']]]);

        $this->assertSame([], $result['sharedUsers']);
        $this->assertSame([], $result['sharedGroups']);
    }

    public function testDropsBlankOrMissingId(): void
    {
        $result = $this->deriver->apply(
            [
                'sharedWith' => [
                    ['type' => 'user', 'id' => '   '],
                    ['type' => 'user'],
                    ['type' => 'group', 'id' => ''],
                ],
            ]
        );

        $this->assertSame([], $result['sharedUsers']);
        $this->assertSame([], $result['sharedGroups']);
    }

    public function testDropsNonArrayEntries(): void
    {
        $result = $this->deriver->apply(['sharedWith' => ['alice', 42, null]]);

        $this->assertSame([], $result['sharedUsers']);
    }

    public function testANonArrayShareListGrantsNothing(): void
    {
        $result = $this->deriver->apply(['sharedWith' => 'alice']);

        $this->assertSame([], $result['sharedUsers']);
    }

    /**
     * One malformed entry must not discard its well-formed siblings.
     */
    public function testKeepsValidEntriesAlongsideAnInvalidOne(): void
    {
        $result = $this->deriver->apply(
            [
                'sharedWith' => [
                    ['type' => 'nonsense', 'id' => 'x'],
                    ['type' => 'user', 'id' => 'alice'],
                ],
            ]
        );

        $this->assertSame(['alice'], $result['sharedUsers']);
    }

    public function testTrimsIds(): void
    {
        $result = $this->deriver->apply(['sharedWith' => [['type' => 'user', 'id' => '  alice  ']]]);

        $this->assertSame(['alice'], $result['sharedUsers']);
    }

    // ── the permission verb, which RBAC cannot express ──

    public function testGrantsMatchesUserWithThePermission(): void
    {
        $shared = [['type' => 'user', 'id' => 'alice', 'permission' => 'run']];

        $this->assertTrue($this->deriver->grants($shared, 'alice', [], ['run']));
    }

    public function testGrantsRefusesUserWithoutThePermission(): void
    {
        $shared = [['type' => 'user', 'id' => 'alice', 'permission' => 'read']];

        $this->assertFalse(
            $this->deriver->grants($shared, 'alice', [], ['run']),
            'a read-only recipient must be refused at the trigger even though RBAC lets them see the flow'
        );
    }

    public function testGrantsMatchesViaGroupMembership(): void
    {
        $shared = [['type' => 'group', 'id' => 'finance', 'permission' => 'run']];

        $this->assertTrue($this->deriver->grants($shared, 'alice', ['finance'], ['run']));
        $this->assertFalse($this->deriver->grants($shared, 'alice', ['legal'], ['run']));
    }

    public function testGrantsRefusesAnonymous(): void
    {
        $shared = [['type' => 'user', 'id' => 'alice', 'permission' => 'run']];

        $this->assertFalse($this->deriver->grants($shared, '', [], ['run']));
    }

    public function testGrantsRefusesWhenPermissionIsAbsent(): void
    {
        $shared = [['type' => 'user', 'id' => 'alice']];

        $this->assertFalse(
            $this->deriver->grants($shared, 'alice', [], ['run']),
            'an entry with no permission must not satisfy an explicit verb check'
        );
    }
}
