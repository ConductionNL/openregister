<?php
/**
 * AppHost GenericSettingsController — auth-posture parity tests.
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

use OCA\OpenRegister\AppHost\Controller\GenericSettingsController;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The register binding MUST be stripped for non-admins (IDOR-safe / ADR-005).
 */
class GenericSettingsControllerTest extends TestCase
{
    private function controller(AppHostSettingsService $svc): GenericSettingsController
    {
        return new GenericSettingsController('myapp', $this->createMock(IRequest::class), $svc);
    }//end controller()

    public function testIndexStripsRegisterForNonAdmin(): void
    {
        $svc = $this->createMock(AppHostSettingsService::class);
        $svc->method('getSettings')->willReturn(['register' => 'secret-uuid', 'isAdmin' => false, 'openregisters' => true]);

        $data = $this->controller($svc)->index()->getData();
        $this->assertArrayNotHasKey('register', $data, 'register UUID must not leak to non-admins');
    }//end testIndexStripsRegisterForNonAdmin()

    public function testIndexKeepsRegisterForAdmin(): void
    {
        $svc = $this->createMock(AppHostSettingsService::class);
        $svc->method('getSettings')->willReturn(['register' => 'secret-uuid', 'isAdmin' => true, 'openregisters' => true]);

        $data = $this->controller($svc)->index()->getData();
        $this->assertSame('secret-uuid', $data['register']);
    }//end testIndexKeepsRegisterForAdmin()

    public function testIndexCarriesNoAdminRequiredAttribute(): void
    {
        // index() is reachable by any authenticated user (it self-gates by stripping).
        $rm = new ReflectionMethod(GenericSettingsController::class, 'index');
        $attrs = array_map(static fn ($a) => $a->getName(), $rm->getAttributes());
        $this->assertContains('OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired', $attrs);
    }//end testIndexCarriesNoAdminRequiredAttribute()

    public function testCreateAndLoadAreFullAdminOnly(): void
    {
        // create()/update()/load() carry NO NoAdminRequired attribute → NC default full-admin gate.
        foreach (['create', 'update', 'load'] as $method) {
            $rm    = new ReflectionMethod(GenericSettingsController::class, $method);
            $attrs = array_map(static fn ($a) => $a->getName(), $rm->getAttributes());
            $this->assertNotContains(
                'OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired',
                $attrs,
                "$method() must remain full-admin-only (no NoAdminRequired)"
            );
        }
    }//end testCreateAndLoadAreFullAdminOnly()
}//end class
