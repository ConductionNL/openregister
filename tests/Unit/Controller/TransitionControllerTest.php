<?php

/**
 * TransitionController status-code contract tests.
 *
 * Pins the F03 verdict that a `NotAuthorizedException` from the
 * TransitionEngine maps to HTTP 403, separately from the 422 used
 * for invalid-transition and the 404 used for missing-object. A
 * future try/catch reorder or wrapper-layer exception change must
 * trip these tests rather than silently flipping the verdict.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\TransitionController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\TransitionController
 */
class TransitionControllerTest extends TestCase
{

    private TransitionController $controller;

    private TransitionEngine&MockObject $engine;

    private IRequest&MockObject $request;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->engine     = $this->createMock(TransitionEngine::class);
        $this->controller = new TransitionController(
            'openregister',
            $this->request,
            $this->engine
        );
    }//end setUp()

    /**
     * Happy path — engine returns the saved object, controller returns 200.
     *
     * @return void
     */
    public function testTransitionReturnsOk(): void
    {
        $this->request->method('getParam')->with('action')->willReturn('open');
        $object = $this->createMock(ObjectEntity::class);
        $object->method('jsonSerialize')->willReturn(['uuid' => 'u-1', 'state' => 'open']);
        $this->engine->method('transition')->willReturn($object);

        $response = $this->controller->transition('obj-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testTransitionReturnsOk()

    /**
     * Missing `action` body field is a client error.
     *
     * @return void
     */
    public function testTransitionReturns400WhenActionMissing(): void
    {
        $this->request->method('getParam')->with('action')->willReturn(null);

        $response = $this->controller->transition('obj-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testTransitionReturns400WhenActionMissing()

    /**
     * R08 / F03 contract: engine raising NotAuthorizedException becomes 403.
     *
     * @return void
     */
    public function testTransitionReturnsForbiddenOnPermissionDenied(): void
    {
        $this->request->method('getParam')->with('action')->willReturn('open');
        $this->engine->method('transition')->willThrowException(
            new NotAuthorizedException(message: 'You do not have permission to transition object "obj-1".')
        );

        $response = $this->controller->transition('obj-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $body = $response->getData();
        $this->assertIsArray($body);
        $this->assertSame(
            'You do not have permission to transition object "obj-1".',
            $body['error'] ?? null
        );
    }//end testTransitionReturnsForbiddenOnPermissionDenied()

    /**
     * Engine RuntimeException (missing object/schema/disallowed) → 422.
     *
     * @return void
     */
    public function testTransitionReturns422OnRuntimeError(): void
    {
        $this->request->method('getParam')->with('action')->willReturn('open');
        $this->engine->method('transition')->willThrowException(
            new RuntimeException('Transition "open" is not allowed from current state "closed".')
        );

        $response = $this->controller->transition('obj-1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testTransitionReturns422OnRuntimeError()

    /**
     * R08 / F03 contract: availableActions also surfaces 403 on denial.
     *
     * @return void
     */
    public function testAvailableActionsReturnsForbiddenOnPermissionDenied(): void
    {
        $this->engine->method('availableActions')->willThrowException(
            new NotAuthorizedException(message: 'You do not have permission to read object "obj-1".')
        );

        $response = $this->controller->availableActions('obj-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $body = $response->getData();
        $this->assertIsArray($body);
        $this->assertSame(
            'You do not have permission to read object "obj-1".',
            $body['error'] ?? null
        );
    }//end testAvailableActionsReturnsForbiddenOnPermissionDenied()

    /**
     * availableActions RuntimeException (missing object) → 404, distinct
     * from the 403 path above.
     *
     * @return void
     */
    public function testAvailableActionsReturns404OnMissingObject(): void
    {
        $this->engine->method('availableActions')->willThrowException(
            new RuntimeException('Object "obj-1" not found.')
        );

        $response = $this->controller->availableActions('obj-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testAvailableActionsReturns404OnMissingObject()
}//end class
