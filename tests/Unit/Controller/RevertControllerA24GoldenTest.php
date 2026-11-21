<?php

/**
 * Golden outcomes of the revert route.
 *
 * Pins, before a restructure, which revert point wins, that a request
 * without one never reaches the handler, the exact status and body of
 * each refusal the handler raises (rights, lock, freeze), and that an
 * allowed revert answers the rendered read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Controller\RevertController;
use OCA\OpenRegister\Controller\Support\RevertControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\RevertController
 * @uses \OCA\OpenRegister\Controller\Support\ObjectLinkRoute
 * @uses \OCA\OpenRegister\Controller\Support\RevertControllerServices
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Object\RevertPoint
 * @uses \OCA\OpenRegister\Exception\LockedException
 * @uses \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @uses \OCA\OpenRegister\Exception\ValidationException
 */
final class RevertControllerA24GoldenTest extends TestCase {

	/**
	 * The handler double.
	 *
	 * @var RevertHandler&MockObject
	 */
	private RevertHandler&MockObject $handler;

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params  The request parameters.
	 * @param ObjectService|null   $objects The render boundary, or null.
	 *
	 * @return RevertController The controller.
	 */
	private function controller(array $params, ?ObjectService $objects = null): RevertController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$this->handler = $this->createMock(RevertHandler::class);

		return new RevertController($request, new RevertControllerServices($this->handler, $objects));
	}//end controller()

	/**
	 * A reverted object.
	 *
	 * @return ObjectEntity The object.
	 */
	private function reverted(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');

		return $object;
	}//end reverted()

	/**
	 * Without a revert point the handler is never asked.
	 *
	 * @return void
	 */
	public function testNoRevertPointIs400(): void {
		$controller = $this->controller(params: ['datetime' => null, 'overwriteVersion' => true]);
		$this->handler->expects($this->never())->method('revert');
		$response = $controller->revert('r', 's', 'u-1');

		$this->assertSame([400, ['error' => 'Must specify either datetime, auditTrailId, or version']], [$response->getStatus(), $response->getData()]);
	}//end testNoRevertPointIs400()

	/**
	 * The datetime wins over the audit trail id, which wins over the version.
	 *
	 * @return void
	 */
	public function testTheRevertPointPrecedence(): void {
		$controller = $this->controller(params: ['datetime' => '2024-01-01T00:00:00', 'auditTrailId' => 7, 'version' => '1.0.0']);
		$this->handler->expects($this->once())->method('revert')
			->with('r', 's', 'u-1', $this->callback(static fn (\OCA\OpenRegister\Service\Object\RevertPoint $point): bool => $point->until instanceof DateTime && $point->overwriteVersion === false))
			->willReturn($this->reverted());
		$controller->revert('r', 's', 'u-1');

		$controller = $this->controller(params: ['auditTrailId' => 7, 'version' => '1.0.0', 'overwriteVersion' => true]);
		$this->handler->expects($this->once())->method('revert')->with('r', 's', 'u-1', $this->equalTo(new \OCA\OpenRegister\Service\Object\RevertPoint(7, true)))->willReturn($this->reverted());
		$controller->revert('r', 's', 'u-1');

		$controller = $this->controller(params: ['version' => '1.0.0']);
		$this->handler->expects($this->once())->method('revert')->with('r', 's', 'u-1', $this->equalTo(new \OCA\OpenRegister\Service\Object\RevertPoint('1.0.0', false)))->willReturn($this->reverted());
		$response = $controller->revert('r', 's', 'u-1');

		$this->assertSame([200, $this->reverted()->jsonSerialize()], [$response->getStatus(), $response->getData()]);
	}//end testTheRevertPointPrecedence()

	/**
	 * An allowed revert answers the read rendered under the caller's rules.
	 *
	 * @return void
	 */
	public function testTheAnswerIsTheRenderedRead(): void {
		$objects = $this->createMock(ObjectService::class);
		$controller = $this->controller(params: ['version' => '2'], objects: $objects);
		$reverted = $this->reverted();
		$this->handler->method('revert')->willReturn($reverted);
		$objects->expects($this->once())->method('renderEntity')->with($reverted, null, true)->willReturn(['masked' => true]);
		$response = $controller->revert('r', 's', 'u-1');

		$this->assertSame([200, ['masked' => true]], [$response->getStatus(), $response->getData()]);
	}//end testTheAnswerIsTheRenderedRead()

	/**
	 * Each refusal keeps its own status and answers a fixed sentence, never the exception's text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function testEveryRefusal(): void {
		$cases = [
			[new DoesNotExistException('gone'), 404, 'Record not found.'],
			[new NotAuthorizedException('no update'), 403, 'You may not restore this record.'],
			[new LockedException('locked by anna'), 423, 'This record is locked by someone else.'],
			[new ObjectStateWriteException('frozen'), 409, 'This record is archived or frozen and cannot be changed.'],
			[new ValidationException('no longer fits'), 422, "This version does not fit the record's schema as it is now."],
			[new \RuntimeException('db down'), 500, 'The record could not be restored.'],
		];
		foreach ($cases as [$exception, $status, $message]) {
			$controller = $this->controller(params: ['auditTrailId' => 3]);
			$this->handler->method('revert')->willThrowException($exception);
			$response = $controller->revert('r', 's', 'u-1');
			$this->assertSame([$status, $message], [$response->getStatus(), $response->getData()['error']]);
		}
	}//end testEveryRefusal()
}//end class
