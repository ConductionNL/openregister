<?php

/**
 * Provisioning creates groups and does nothing else.
 *
 * The dangerous edits to this class are the tempting ones: adding the installing
 * admin so the app "just works", or removing a group that is no longer declared.
 * Both are refused by design — seeding grants access nobody approved, and
 * deleting a group destroys its memberships and shares irreversibly. These tests
 * exist to make either change fail loudly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/declared-group-provisioning/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for {@see GroupProvisioner}.
 */
class GroupProvisionerTest extends TestCase
{

    /**
     * Mocked group manager.
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * System under test.
     *
     * @var GroupProvisioner
     */
    private GroupProvisioner $provisioner;


    /**
     * Build the provisioner with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->provisioner  = new GroupProvisioner(
            $this->groupManager,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()


    /**
     * A missing group is created; an existing one is left alone.
     *
     * @return void
     */
    public function testCreatesOnlyMissingGroups(): void
    {
        $this->groupManager->method('groupExists')
            ->willReturnMap([['bestaat', true], ['bestaat-niet', false]]);

        $this->groupManager->expects($this->once())
            ->method('createGroup')
            ->with('bestaat-niet');

        $result = $this->provisioner->provision(groups: ['bestaat', 'bestaat-niet'], declaredBy: 'testapp');

        $this->assertSame(['bestaat-niet'], $result['created']);
        $this->assertSame(['bestaat'], $result['existing']);
    }//end testCreatesOnlyMissingGroups()


    /**
     * Provisioning is create-only: no membership is ever written.
     *
     * A created group starts EMPTY, so it denies every caller until an
     * administrator populates it. That is the intended contract.
     *
     * @return void
     */
    public function testNeverAddsMembers(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->expects($this->never())->method('addUser');

        $this->groupManager->method('groupExists')->willReturn(false);
        $this->groupManager->method('createGroup')->willReturn($group);
        // A membership-seeding implementation would have to resolve users first.
        $this->groupManager->expects($this->never())->method('get');

        $this->provisioner->provision(groups: ['nieuw'], declaredBy: 'testapp');
    }//end testNeverAddsMembers()


    /**
     * One failing group never costs the others.
     *
     * Provisioning runs inside imports and background jobs; a backend that
     * refuses one creation must not abort work that is otherwise complete.
     *
     * @return void
     */
    public function testOneFailureDoesNotStopTheRest(): void
    {
        $this->groupManager->method('groupExists')->willReturn(false);
        $this->groupManager->method('createGroup')
            ->willReturnCallback(
                static function (string $gid) {
                    if ($gid === 'stuk') {
                        throw new RuntimeException('read-only backend');
                    }

                    return null;
                }
            );

        $result = $this->provisioner->provision(groups: ['eerste', 'stuk', 'laatste'], declaredBy: 'testapp');

        $this->assertSame(['eerste', 'laatste'], $result['created']);
        $this->assertSame(['stuk'], $result['failed']);
    }//end testOneFailureDoesNotStopTheRest()


    /**
     * An uncountable backend reports UNKNOWN, not zero.
     *
     * `IGroup::count()` returns `int|bool` and hands back `false` on backends
     * that cannot count. Collapsing that to 0 would report a fully populated
     * group as empty — the exact false alarm the inventory exists to prevent.
     *
     * @return void
     */
    public function testUncountableBackendReportsUnknownNotEmpty(): void
    {
        $countable = $this->createMock(IGroup::class);
        $countable->method('count')->willReturn(4);

        $uncountable = $this->createMock(IGroup::class);
        $uncountable->method('count')->willReturn(false);

        $this->groupManager->method('get')
            ->willReturnMap(
                [
                    ['telbaar', $countable],
                    ['ontelbaar', $uncountable],
                    ['weg', null],
                ]
            );

        $inventory = $this->provisioner->inventory(groups: ['telbaar', 'ontelbaar', 'weg']);

        $this->assertSame(4, $inventory['telbaar']['members']);
        $this->assertNull($inventory['ontelbaar']['members'], 'unknown, not empty');
        $this->assertFalse($inventory['weg']['exists']);
    }//end testUncountableBackendReportsUnknownNotEmpty()


}//end class
