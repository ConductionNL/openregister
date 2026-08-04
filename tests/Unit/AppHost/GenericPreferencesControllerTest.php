<?php
/**
 * AppHost GenericPreferencesController — per-user scoping + leak-safe tests.
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

use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Preferences are strictly session-user-scoped (no IDOR surface), keys are
 * sanitised into the `pref_` namespace, and anonymous callers get 401.
 */
class GenericPreferencesControllerTest extends TestCase
{
    private function controller(IConfig $config, ?string $uid='alice'): GenericPreferencesController
    {
        $userSession = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $userSession->method('getUser')->willReturn($user);
        }

        return new GenericPreferencesController(
            'myapp',
            $this->createMock(IRequest::class),
            $config,
            $userSession
        );
    }//end controller()

    public function testGetPreferenceReadsSessionUserUnderLeafAppNamespace(): void
    {
        // Scenario: Preference persists per user — the userId always comes from
        // the session and the value lives under the LEAF app id.
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())
            ->method('getUserValue')
            ->with('alice', 'myapp', 'pref_sidebar-width', '')
            ->willReturn('320');

        $response = $this->controller($config)->getPreference('sidebar-width');
        $this->assertSame(['value' => '320'], $response->getData());
    }//end testGetPreferenceReadsSessionUserUnderLeafAppNamespace()

    public function testGetPreferenceReturnsNullWhenUnset(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');

        $response = $this->controller($config)->getPreference('sidebar-width');
        $this->assertSame(['value' => null], $response->getData());
    }//end testGetPreferenceReturnsNullWhenUnset()

    public function testSetPreferenceStoresValueForSessionUser(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'myapp', 'pref_theme', 'dark');

        $response = $this->controller($config)->setPreference('theme', 'dark');
        $this->assertSame(['value' => 'dark'], $response->getData());
    }//end testSetPreferenceStoresValueForSessionUser()

    public function testSetPreferenceEmptyValueDeletes(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())
            ->method('deleteUserValue')
            ->with('alice', 'myapp', 'pref_theme');
        $config->expects($this->never())->method('setUserValue');

        $response = $this->controller($config)->setPreference('theme', '');
        $this->assertSame(['value' => null], $response->getData());
    }//end testSetPreferenceEmptyValueDeletes()

    public function testAnonymousCallerGets401(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->never())->method('getUserValue');

        $get = $this->controller($config, uid: null)->getPreference('theme');
        $set = $this->controller($config, uid: null)->setPreference('theme', 'dark');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $get->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $set->getStatus());
    }//end testAnonymousCallerGets401()

    public function testKeySanitisationBlocksNamespaceEscape(): void
    {
        // A key that sanitises to nothing must be rejected, and no IConfig
        // access may happen — callers can never reach values outside `pref_`.
        $config = $this->createMock(IConfig::class);
        $config->expects($this->never())->method('getUserValue');

        $response = $this->controller($config)->getPreference('///');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testKeySanitisationBlocksNamespaceEscape()

    public function testMixedKeyIsSanitisedIntoSafeCharset(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())
            ->method('getUserValue')
            ->with('alice', 'myapp', 'pref_mykey', '')
            ->willReturn('');

        $this->controller($config)->getPreference('My_Key!');
    }//end testMixedKeyIsSanitisedIntoSafeCharset()
}//end class
