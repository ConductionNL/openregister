<?php

/**
 * Unit tests for ActivityLinksController — the Tier-2 read-only REST controller
 * for the `activity` integration leaf.
 *
 * Covers HTTP status mapping (200/400/404/501), cursor pagination, and the
 * filter-dropdown endpoints (types/actors). The DB and NC Activity layers are
 * not exercised here; ActivityFilterService is fully mocked.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-activity/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Controller\ActivityLinksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ActivityFilterService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ActivityLinksControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ActivityLinksControllerTest extends TestCase
{

    /**
     * HTTP request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Activity filter service mock.
     *
     * @var ActivityFilterService&MockObject
     */
    private ActivityFilterService&MockObject $filterService;

    /**
     * Object service mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Controller under test.
     *
     * @var ActivityLinksController
     */
    private ActivityLinksController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->filterService = $this->createMock(ActivityFilterService::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->controller = new ActivityLinksController(
            'openregister',
            $this->request,
            $this->filterService,
            $this->objectService,
        );
    }//end setUp()

    private function mockObject(string $uuid='test-uuid-1234'): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);
        return $object;
    }//end mockObject()

    public function testIndexReturns501WhenActivityUnavailable(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(false);

        $response = $this->controller->index('reg', 'sch', 'obj');

        $this->assertSame(501, $response->getStatus());
        $this->assertSame('APP_NOT_AVAILABLE', $response->getData()['code']);
    }//end testIndexReturns501WhenActivityUnavailable()

    public function testIndexReturns404WhenObjectMissing(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(true);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn(null);

        $response = $this->controller->index('reg', 'sch', 'missing');

        $this->assertSame(404, $response->getStatus());
    }//end testIndexReturns404WhenObjectMissing()

    public function testIndexReturnsResultsWithCursorPagination(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(true);
        $this->mockObject();
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return match ($key) {
                    'limit'  => 10,
                    'cursor' => null,
                    default  => $default,
                };
            }
        );
        $this->filterService->method('getActivityEntries')->willReturn(
            [
                'results'    => [
                    [
                        'id'        => '7',
                        'type'      => 'files',
                        'timestamp' => 1779002000,
                        'actor_id'  => 'alice',
                    ],
                ],
                'total'      => 1,
                'nextCursor' => null,
            ]
        );

        $response = $this->controller->index('reg', 'sch', 'obj');
        $data     = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertCount(1, $data['results']);
        $this->assertSame('7', $data['results'][0]['id']);
        $this->assertSame(1, $data['total']);
        $this->assertNull($data['nextCursor']);
    }//end testIndexReturnsResultsWithCursorPagination()

    public function testIndexReturns500OnUnexpectedException(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(true);
        $this->mockObject();
        $this->request->method('getParam')->willReturn(null);
        $this->filterService->method('getActivityEntries')->willThrowException(
            new Exception('DB failure')
        );

        $response = $this->controller->index('reg', 'sch', 'obj');

        $this->assertSame(500, $response->getStatus());
    }//end testIndexReturns500OnUnexpectedException()

    public function testTypesReturns501WhenActivityUnavailable(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(false);

        $response = $this->controller->types();

        $this->assertSame(501, $response->getStatus());
    }//end testTypesReturns501WhenActivityUnavailable()

    public function testTypesReturns400WhenObjectParamMalformed(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(true);
        $this->request->method('getParam')->willReturn('bad/format');

        $response = $this->controller->types();

        $this->assertSame(400, $response->getStatus());
    }//end testTypesReturns400WhenObjectParamMalformed()

    public function testTypesReturnsDistinctValues(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(true);
        $this->mockObject();
        $this->request->method('getParam')->willReturn('reg/sch/obj');
        $this->filterService->method('getActivityTypes')->willReturn(['files', 'comments']);

        $response = $this->controller->types();
        $data     = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['files', 'comments'], $data['results']);
        $this->assertSame(2, $data['total']);
    }//end testTypesReturnsDistinctValues()

    public function testActorsReturns501WhenActivityUnavailable(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(false);

        $response = $this->controller->actors();

        $this->assertSame(501, $response->getStatus());
    }//end testActorsReturns501WhenActivityUnavailable()

    public function testActorsReturns400WhenObjectParamMalformed(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(true);
        $this->request->method('getParam')->willReturn('');

        $response = $this->controller->actors();

        $this->assertSame(400, $response->getStatus());
    }//end testActorsReturns400WhenObjectParamMalformed()

    public function testActorsReturnsDistinctUsers(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(true);
        $this->mockObject();
        $this->request->method('getParam')->willReturn('reg/sch/obj');
        $this->filterService->method('getActivityActors')->willReturn(['alice', 'bob']);

        $response = $this->controller->actors();
        $data     = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['alice', 'bob'], $data['results']);
        $this->assertSame(2, $data['total']);
    }//end testActorsReturnsDistinctUsers()

    public function testTypesReturns404WhenObjectNotFound(): void
    {
        $this->filterService->method('isActivityAvailable')->willReturn(true);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willThrowException(new DoesNotExistException('Not found'));
        $this->request->method('getParam')->willReturn('reg/sch/missing');

        $response = $this->controller->types();

        $this->assertSame(404, $response->getStatus());
    }//end testTypesReturns404WhenObjectNotFound()
}//end class
