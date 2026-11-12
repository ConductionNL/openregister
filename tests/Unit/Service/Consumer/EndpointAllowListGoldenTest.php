<?php

/**
 * Golden outcomes of an endpoint's users/groups allow-list: who is admitted and who is refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Consumer;

use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\Consumer\EndpointAllowList;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Consumer\EndpointAudience;

/**
 * @covers \OCA\OpenRegister\Service\Consumer\EndpointAllowList
 */
class EndpointAllowListGoldenTest extends TestCase
{
    /**
     * Each case: users list, groups list, whether a group manager is present, expected outcome.
     *
     * @return array<string, array{array, array, bool, string}>
     */
    public static function cases(): array
    {
        return [
            'open endpoint admits'               => [[], [], true, 'allowed'],
            'open endpoint without groups admits' => [[], [], false, 'allowed'],
            'uid in users admits'                => [['alice'], ['other'], true, 'allowed'],
            'email in users admits'              => [['alice@example.org'], [], true, 'allowed'],
            'group in groups admits'             => [['bob'], ['staff'], true, 'allowed'],
            'groups only, member admits'         => [[], ['staff'], true, 'allowed'],
            'groups only, non member refused'    => [[], ['admin'], true, 'refused'],
            'users only, other user refused'     => [['bob'], [], true, 'refused'],
            'group list without manager refused' => [[], ['staff'], false, 'refused'],
            'neither list matches refused'       => [['bob'], ['admin'], true, 'refused'],
        ];
    }

    #[DataProvider('cases')]
    public function testOutcome(array $users, array $groups, bool $withManager, string $expected): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getEMailAddress')->willReturn('alice@example.org');

        $manager = null;
        if ($withManager === true) {
            $staff = $this->createMock(IGroup::class);
            $staff->method('getGID')->willReturn('staff');
            $manager = $this->createMock(IGroupManager::class);
            $manager->method('getUserGroups')->willReturn([$staff]);
        }

        $outcome = 'allowed';
        try {
            (new EndpointAllowList($manager))->assertAdmits($user, new EndpointAudience($users, $groups));
        } catch (AuthenticationException $e) {
            $outcome = 'refused';
            $this->assertSame('Not authorized', $e->getMessage());
        }

        $this->assertSame($expected, $outcome);
    }
}
