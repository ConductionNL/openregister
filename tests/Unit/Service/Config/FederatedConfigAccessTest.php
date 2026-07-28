<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Service\Config\FederatedConfigAccess;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class FederatedConfigAccessTest extends TestCase
{
    private IGroupManager $groups;

    private string $publishGroups = '';

    private FederatedConfigAccess $access;

    protected function setUp(): void
    {
        $this->groups = $this->createMock(IGroupManager::class);
        $appConfig    = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => ($key === 'federated_config_publish_groups' ? $this->publishGroups : '')
        );

        $this->access = new FederatedConfigAccess($this->groups, $appConfig);
    }

    private function user(string $uid): IUser
    {
        $u = $this->createMock(IUser::class);
        $u->method('getUID')->willReturn($uid);
        return $u;
    }

    public function testNullUserIsNeverAllowed(): void
    {
        $this->assertFalse($this->access->canPublish(null));
    }

    public function testAdminIsAlwaysAllowed(): void
    {
        $this->publishGroups = 'editors';
        $this->groups->method('isAdmin')->willReturn(true);

        $this->assertTrue($this->access->canPublish($this->user('root')));
    }

    public function testEmptyGroupListAllowsAnySignedInUser(): void
    {
        $this->publishGroups = '';
        $this->groups->method('isAdmin')->willReturn(false);

        $this->assertTrue($this->access->canPublish($this->user('alice')));
    }

    public function testSetGroupListGatesByMembership(): void
    {
        $this->publishGroups = 'editors, publishers';
        $this->groups->method('isAdmin')->willReturn(false);
        $this->groups->method('getUserGroupIds')->willReturn(['staff', 'publishers']);

        $this->assertTrue($this->access->canPublish($this->user('bob')));
    }

    public function testUserOutsideTheGroupsIsDenied(): void
    {
        $this->publishGroups = 'editors';
        $this->groups->method('isAdmin')->willReturn(false);
        $this->groups->method('getUserGroupIds')->willReturn(['staff']);

        $this->assertFalse($this->access->canPublish($this->user('carol')));
    }
}
