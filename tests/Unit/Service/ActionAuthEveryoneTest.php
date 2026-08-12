<?php

/**
 * The matrix can say "everyone", and absence still means no.
 *
 * Before this the matrix was fail-closed with no way to express an open
 * action: an unknown entry, an empty list and `['admin']` all denied
 * non-admins. That made it unusable for any operation that is currently open —
 * naming the action would have locked out everyone who can do it today, which
 * is why an already-open operation never got an entry and so never became
 * configurable at all.
 *
 * The risk of adding an "everyone" value is that it quietly becomes the
 * DEFAULT, turning a fail-closed matrix into a fail-open one. That is the
 * property most of these tests are about.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\AppHost\Service\GenericActionAuthService
 */
class ActionAuthEveryoneTest extends TestCase
{


    /**
     * A service whose matrix is the given map, for a non-admin in no groups.
     *
     * @param array $matrix The action matrix.
     * @param array $groups The user's groups.
     * @param bool  $admin  Whether the user is an admin.
     *
     * @return array{0: GenericActionAuthService, 1: IUser}
     */
    private function serviceWith(array $matrix, array $groups=[], bool $admin=false): array
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn(json_encode($matrix));

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($admin);
        $groupManager->method('getUserGroupIds')->willReturn($groups);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        return [new GenericActionAuthService('openregister', $appConfig, $groupManager), $user];

    }//end serviceWith()


    /**
     * An explicit everyone-grant lets a non-admin through.
     *
     * @return void
     */
    public function testEveryoneGrantAdmitsANonAdmin(): void
    {
        [$service, $user] = $this->serviceWith(['flow.create' => ['@authenticated']]);

        $this->assertTrue($service->can(user: $user, action: 'flow.create'));

    }//end testEveryoneGrantAdmitsANonAdmin()


    /**
     * THE PROPERTY THAT MATTERS: absence still denies.
     *
     * An "everyone" value is only safe while it has to be written down. If a
     * missing entry started meaning "open", every action anyone forgot to seed
     * would be world-writable — the matrix would have gone from fail-closed to
     * fail-open on the strength of a convenience.
     *
     * @return void
     */
    public function testAnUnlistedActionStillDenies(): void
    {
        [$service, $user] = $this->serviceWith(['flow.create' => ['@authenticated']]);

        $this->assertFalse($service->can(user: $user, action: 'flow.delete'));
        $this->assertFalse($service->can(user: $user, action: 'something.nobody.seeded'));

    }//end testAnUnlistedActionStillDenies()


    /**
     * An empty list and an admin-only list still deny, as they always did.
     *
     * @return void
     */
    public function testEmptyAndAdminOnlyStillDeny(): void
    {
        [$service, $user] = $this->serviceWith(
            [
                'flow.run'    => [],
                'flow.update' => ['admin'],
            ]
        );

        $this->assertFalse($service->can(user: $user, action: 'flow.run'));
        $this->assertFalse($service->can(user: $user, action: 'flow.update'));

    }//end testEmptyAndAdminOnlyStillDeny()


    /**
     * Group grants are unaffected by the new value.
     *
     * @return void
     */
    public function testGroupGrantsStillWork(): void
    {
        [$service, $user] = $this->serviceWith(['flow.run' => ['flow-authors']], groups: ['flow-authors']);
        $this->assertTrue($service->can(user: $user, action: 'flow.run'));

        [$other, $otherUser] = $this->serviceWith(['flow.run' => ['flow-authors']], groups: ['everyone-else']);
        $this->assertFalse($other->can(user: $otherUser, action: 'flow.run'));

    }//end testGroupGrantsStillWork()


    /**
     * An admin passes regardless, as before.
     *
     * @return void
     */
    public function testAdminAlwaysPasses(): void
    {
        [$service, $user] = $this->serviceWith(['flow.create' => []], admin: true);

        $this->assertTrue($service->can(user: $user, action: 'flow.create'));

    }//end testAdminAlwaysPasses()


    /**
     * `requireAction` throws where `can` is false, so a controller that forgets
     * to check still refuses.
     *
     * @return void
     */
    public function testRequireActionThrowsWhenDenied(): void
    {
        [$service, $user] = $this->serviceWith(['flow.create' => ['admin']]);

        $this->expectException(OCSForbiddenException::class);
        $service->requireAction(user: $user, action: 'flow.create');

    }//end testRequireActionThrowsWhenDenied()


    /**
     * THE SEED PRESERVES TODAY'S ACCESS.
     *
     * The four flow rights are seeded `@authenticated` precisely because the
     * endpoints were already open to any signed-in member of an organisation.
     * A seed that defaulted to admin-only would lock out every non-admin flow
     * author on every instance on upgrade — a breaking change wearing a
     * feature's clothes.
     *
     * @return void
     */
    public function testTheShippedSeedDoesNotLockOutExistingAuthors(): void
    {
        $seed = json_decode(file_get_contents(__DIR__.'/../../../lib/actions.seed.json'), true);
        $this->assertIsArray($seed['actions'] ?? null, 'the seed must carry an actions map');

        [$service, $user] = $this->serviceWith($seed['actions']);

        foreach (['flow.create', 'flow.update', 'flow.delete', 'flow.run'] as $action) {
            $this->assertTrue(
                $service->can(user: $user, action: $action),
                sprintf('seeding "%s" locked out a non-admin who could do it before', $action)
            );
        }

    }//end testTheShippedSeedDoesNotLockOutExistingAuthors()


}//end class
