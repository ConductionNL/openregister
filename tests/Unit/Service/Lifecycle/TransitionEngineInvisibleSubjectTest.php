<?php

/**
 * OpenRegister TransitionEngine invisible-subject tests
 *
 * A transition on an object the caller may not READ used to answer HTTP 500.
 * `ObjectService::find()` is RBAC-filtered: for an id-only lookup the
 * cross-table search drops the row the caller may not see and throws OCP's
 * `DoesNotExistException`, and a schema-level read denial throws
 * `NotAuthorizedException`. Neither is a `RuntimeException`, so the first
 * escaped every branch of `TransitionController` and became a 500.
 *
 * The contract pinned here: an object the caller cannot see is reported
 * exactly like an object that does not exist (`LifecycleSubjectNotFoundException`,
 * HTTP 404), the same answer `GET /api/objects/{register}/{schema}/{id}` gives,
 * so the write endpoint does not leak existence the read endpoint hides. An
 * object the caller CAN see but may not update stays a 403.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Controller\TransitionController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
class TransitionEngineInvisibleSubjectTest extends TestCase {
	private const OBJECT_ID = '00000000-0000-0000-0000-0000000000ee';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private PermissionHandler&MockObject $permission;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->permission = $this->createMock(PermissionHandler::class);
	}//end setUp()

	/**
	 * Build the real engine around the doubled collaborators.
	 */
	private function engine(): TransitionEngine {
		return new TransitionEngine(
			$this->objectService,
			$this->schemaMapper,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->permission,
			$this->createMock(RegisterMapper::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			new LifecycleWriteBoundary(new LifecycleActionContext()),
			$this->createMock(LifecycleActionProviderRegistry::class)
		);
	}//end engine()

	/**
	 * Run the real controller over the real engine and return the HTTP status.
	 */
	private function transitionStatus(): int {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => ['action' => 'approve'][$key] ?? null
		);
		$controller = new TransitionController('openregister', $request, $this->engine());

		return $controller->transition(self::OBJECT_ID)->getStatus();
	}//end transitionStatus()

	/**
	 * Run the real controller's available-actions read and return the HTTP status.
	 */
	private function availableActionsStatus(): int {
		$controller = new TransitionController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->engine()
		);

		return $controller->availableActions(self::OBJECT_ID)->getStatus();
	}//end availableActionsStatus()

	/**
	 * Data provider: the two ways an RBAC-filtered find refuses an invisible object.
	 *
	 * @return array<string, array{0: Throwable}>
	 */
	public static function invisibleFindFailures(): array {
		return [
			'row filtered out by the RBAC query' => [
				new DoesNotExistException("Object with identifier '" . self::OBJECT_ID . "' not found in any magic table"),
			],
			'read denied by the permission check' => [
				new NotAuthorizedException(message: 'Insufficient permissions to read objects of this schema'),
			],
		];
	}//end invisibleFindFailures()

	/**
	 * The engine reports an object the caller cannot read as not found.
	 *
	 * @dataProvider invisibleFindFailures
	 */
	public function testTransitionOnInvisibleObjectIsSubjectNotFound(Throwable $failure): void {
		$this->objectService->method('find')->willThrowException($failure);

		$this->expectException(LifecycleSubjectNotFoundException::class);
		$this->expectExceptionMessage(sprintf('Object "%s" not found.', self::OBJECT_ID));

		$this->engine()->transition(self::OBJECT_ID, 'approve');
	}//end testTransitionOnInvisibleObjectIsSubjectNotFound()

	/**
	 * End to end through the controller: 404, the same as GET, never 500 or 403.
	 *
	 * @dataProvider invisibleFindFailures
	 */
	public function testTransitionEndpointAnswers404ForInvisibleObject(Throwable $failure): void {
		$this->objectService->method('find')->willThrowException($failure);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->transitionStatus());
	}//end testTransitionEndpointAnswers404ForInvisibleObject()

	/**
	 * The sibling read endpoint takes the same find() and must not 500 either.
	 *
	 * @dataProvider invisibleFindFailures
	 */
	public function testAvailableActionsEndpointAnswers404ForInvisibleObject(Throwable $failure): void {
		$this->objectService->method('find')->willThrowException($failure);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->availableActionsStatus());
	}//end testAvailableActionsEndpointAnswers404ForInvisibleObject()

	/**
	 * Visible but not updatable stays a 403: the caller can see the object, so
	 * saying it is forbidden leaks nothing, and it is the truthful answer.
	 */
	public function testTransitionOnVisibleButNotUpdatableObjectStays403(): void {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJECT_ID);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['status' => 'ingediend']);
		$this->objectService->method('find')->willReturn($object);
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->permission->method('hasPermission')->willReturn(false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->transitionStatus());
	}//end testTransitionOnVisibleButNotUpdatableObjectStays403()
}//end class
