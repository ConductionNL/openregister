<?php

declare(strict_types=1);

/**
 * DsarCaseController Unit Tests
 *
 * Verifies the case-management controller's auth posture (every method
 * @NoAdminRequired, never @PublicPage, @NoCSRFRequired only on the download),
 * route↔method reachability (no orphan methods, no orphan routes), anonymous
 * rejection, and the case-level access guard (handler-own allowed, others
 * refused, fail-closed).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DsarCaseController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Test class for DsarCaseController.
 */
class DsarCaseControllerTest extends TestCase
{

    /**
     * The controller methods that back the case-management routes.
     *
     * @var array<int, string>
     */
    private const ROUTED_METHODS = [
        'create',
        'transition',
        'evidence',
        'redact',
        'generateBundle',
        'downloadBundle',
        'dossier',
    ];

    /**
     * Whether a docblock declares the given annotation as an actual tag line
     * (e.g. ` * @PublicPage`), not merely a prose mention inside a sentence.
     * Mirrors the route-auth gate's line-anchored annotation match.
     *
     * @param string $doc        The docblock text.
     * @param string $annotation The annotation name without the leading `@`.
     *
     * @return bool True when the annotation appears as a tag line.
     */
    private function hasAnnotation(string $doc, string $annotation): bool
    {
        return (bool) preg_match('/^\s*\*\s*@'.preg_quote($annotation, '/').'\b/m', $doc);

    }//end hasAnnotation()

    /**
     * Build a controller wired with mocks + an optional session user.
     *
     * @param IUserSession|null   $userSession Session (null → build one with a user).
     * @param CaseObjectAccessor|null $accessor Accessor mock.
     * @param CaseAccessControl|null  $access   Access-control mock.
     *
     * @return DsarCaseController
     */
    private function build(
        ?IUserSession $userSession=null,
        ?CaseObjectAccessor $accessor=null,
        ?CaseAccessControl $access=null
    ): DsarCaseController {
        if ($userSession === null) {
            $userSession = $this->createMock(IUserSession::class);
            $user        = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('handler1');
            $userSession->method('getUser')->willReturn($user);
        }

        return new DsarCaseController(
            'openregister',
            $this->createMock(IRequest::class),
            $this->createMock(ObjectService::class),
            ($accessor ?? $this->createMock(CaseObjectAccessor::class)),
            ($access ?? $this->createMock(CaseAccessControl::class)),
            $this->createMock(TransitionEngine::class),
            $this->createMock(EvidenceHarvestService::class),
            $this->createMock(RedactionWriteService::class),
            $this->createMock(ExportBundleService::class),
            $userSession
        );

    }//end build()

    /**
     * Every routed method declares @NoAdminRequired and never @PublicPage.
     *
     * @return void
     */
    public function testEveryMethodIsNoAdminRequiredAndNeverPublic(): void
    {
        $reflection = new \ReflectionClass(DsarCaseController::class);
        foreach (self::ROUTED_METHODS as $method) {
            $doc = (string) $reflection->getMethod($method)->getDocComment();
            $this->assertTrue(
                $this->hasAnnotation($doc, 'NoAdminRequired'),
                $method.' must be @NoAdminRequired'
            );
            $this->assertFalse(
                $this->hasAnnotation($doc, 'PublicPage'),
                $method.' must never be @PublicPage'
            );
        }

    }//end testEveryMethodIsNoAdminRequiredAndNeverPublic()

    /**
     * @NoCSRFRequired appears ONLY on the download method.
     *
     * @return void
     */
    public function testNoCsrfOnlyOnDownload(): void
    {
        $reflection = new \ReflectionClass(DsarCaseController::class);
        foreach (self::ROUTED_METHODS as $method) {
            $doc       = (string) $reflection->getMethod($method)->getDocComment();
            $hasNoCsrf = $this->hasAnnotation($doc, 'NoCSRFRequired');
            if ($method === 'downloadBundle') {
                $this->assertTrue($hasNoCsrf, 'download must be @NoCSRFRequired');
            } else {
                $this->assertFalse($hasNoCsrf, $method.' must NOT be @NoCSRFRequired');
            }
        }

    }//end testNoCsrfOnlyOnDownload()

    /**
     * Route↔method reachability: every case route targets an existing method,
     * and every routed method has a route (no orphans, ADR-016/029).
     *
     * @return void
     */
    public function testRouteMethodReachability(): void
    {
        $routes  = (include __DIR__.'/../../../appinfo/routes.php')['routes'];
        $mapped  = [];
        foreach ($routes as $route) {
            if (str_starts_with((string) $route['name'], 'dsarCase#') === true) {
                $mapped[] = substr((string) $route['name'], strlen('dsarCase#'));
            }
        }

        $reflection = new \ReflectionClass(DsarCaseController::class);

        // No orphan route: every mapped method exists on the controller.
        foreach ($mapped as $method) {
            $this->assertTrue($reflection->hasMethod($method), 'route targets missing method '.$method);
        }

        // No orphan method: every routed method is mapped.
        sort($mapped);
        $expected = self::ROUTED_METHODS;
        sort($expected);
        $this->assertSame($expected, $mapped);

    }//end testRouteMethodReachability()

    /**
     * Anonymous callers are rejected before any case is touched (401).
     *
     * @return void
     */
    public function testAnonymousRejected(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->expects($this->never())->method('load');

        $controller = $this->build($userSession, $accessor);

        $response = $controller->evidence('case-1');
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAnonymousRejected()

    /**
     * The guard refuses when case-level access control denies (403), and does
     * not proceed to the harvest.
     *
     * @return void
     */
    public function testAccessDeniedRefusesWithForbidden(): void
    {
        $case     = new ObjectEntity();
        $case->setObject(['handler' => 'handler2']);
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn($case);

        $access = $this->createMock(CaseAccessControl::class);
        $access->method('mayAct')->willReturn(false);

        $harvest = $this->createMock(EvidenceHarvestService::class);
        $harvest->expects($this->never())->method('harvest');

        $controller = new DsarCaseController(
            'openregister',
            $this->createMock(IRequest::class),
            $this->createMock(ObjectService::class),
            $accessor,
            $access,
            $this->createMock(TransitionEngine::class),
            $harvest,
            $this->createMock(RedactionWriteService::class),
            $this->createMock(ExportBundleService::class),
            $this->userSessionWith('handler1')
        );

        $response = $controller->evidence('case-1');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testAccessDeniedRefusesWithForbidden()

    /**
     * A missing/unauthorised case yields 404 (no enumeration oracle).
     *
     * @return void
     */
    public function testMissingCaseYields404(): void
    {
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn(null);

        $controller = $this->build($this->userSessionWith('handler1'), $accessor);

        $response = $controller->dossier('case-x');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testMissingCaseYields404()

    /**
     * Build a user session for a uid.
     *
     * @param string $uid The caller uid.
     *
     * @return IUserSession
     */
    private function userSessionWith(string $uid): IUserSession
    {
        $userSession = $this->createMock(IUserSession::class);
        $user        = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $userSession->method('getUser')->willReturn($user);
        return $userSession;

    }//end userSessionWith()
}//end class
