<?php
/**
 * AppHost GenericActionAuthService — ADR-023 action RBAC posture tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Verifies admin break-glass, fail-closed default-deny and group intersection.
 */
class GenericActionAuthServiceTest extends TestCase
{
    private function service(string $matrixJson, bool $isAdmin, array $userGroups = []): GenericActionAuthService
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn($matrixJson);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);
        $groupManager->method('getUserGroupIds')->willReturn($userGroups);

        return new GenericActionAuthService('myapp', $appConfig, $groupManager);
    }//end service()

    private function user(): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        return $user;
    }//end user()

    public function testAdminAlwaysPasses(): void
    {
        $svc = $this->service('{"item.publish":["editors"]}', isAdmin: true);
        $svc->requireAction($this->user(), 'item.publish');
        $this->assertTrue($svc->can($this->user(), 'item.publish'));
    }//end testAdminAlwaysPasses()

    public function testUndeclaredActionIsAdminOnlyFailClosed(): void
    {
        // Empty matrix → getAllowedGroups falls back to ["admin"] → non-admin denied.
        $svc = $this->service('{}', isAdmin: false, userGroups: ['editors']);
        $this->expectException(OCSForbiddenException::class);
        $svc->requireAction($this->user(), 'item.publish');
    }//end testUndeclaredActionIsAdminOnlyFailClosed()

    public function testMalformedMatrixFailsClosed(): void
    {
        $svc = $this->service('{not json', isAdmin: false, userGroups: ['editors']);
        $this->assertFalse($svc->can($this->user(), 'item.publish'));
    }//end testMalformedMatrixFailsClosed()

    public function testNonAdminPassesWhenGroupIntersects(): void
    {
        $svc = $this->service('{"item.publish":["editors","reviewers"]}', isAdmin: false, userGroups: ['reviewers']);
        $svc->requireAction($this->user(), 'item.publish');
        $this->assertTrue($svc->can($this->user(), 'item.publish'));
    }//end testNonAdminPassesWhenGroupIntersects()

    public function testNonAdminDeniedWhenNoGroupIntersects(): void
    {
        $svc = $this->service('{"item.publish":["editors"]}', isAdmin: false, userGroups: ['viewers']);
        $this->assertFalse($svc->can($this->user(), 'item.publish'));
    }//end testNonAdminDeniedWhenNoGroupIntersects()

    public function testAdminOnlyEntryDeniesNonAdmin(): void
    {
        // ['admin'] entry means admin-only — a non-admin in some group still fails.
        $svc = $this->service('{"item.publish":["admin"]}', isAdmin: false, userGroups: ['admin']);
        $this->expectException(OCSForbiddenException::class);
        $svc->requireAction($this->user(), 'item.publish');
    }//end testAdminOnlyEntryDeniesNonAdmin()
}//end class
