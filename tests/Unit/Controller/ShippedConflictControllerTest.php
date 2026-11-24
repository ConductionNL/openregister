<?php

/**
 * The admin routes over the pending shipped conflicts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ShippedConflictController;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConflictServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * GET lists, POST accepts; a refusal is 409 with its reason, an unknown schema 404.
 *
 * @covers \OCA\OpenRegister\Controller\ShippedConflictController
 */
class ShippedConflictControllerTest extends TestCase {

	/**
	 * The controller over a service double.
	 *
	 * @param ShippedConflictServiceInterface $conflicts The service.
	 *
	 * @return ShippedConflictController
	 */
	private function controller(ShippedConflictServiceInterface $conflicts): ShippedConflictController {
		return new ShippedConflictController(appName: 'openregister', request: $this->createMock(IRequest::class), conflicts: $conflicts);
	}//end controller()

	/**
	 * The list passes the schema through and answers 200 without accepting anything.
	 *
	 * @return void
	 */
	public function testTheListAnswersThePendingConflicts(): void {
		$conflicts = $this->createMock(ShippedConflictServiceInterface::class);
		$conflicts->expects($this->once())->method('pending')->with('learner-profile')->willReturn(
			['schema' => 'learner-profile', 'schemaId' => 329, 'app' => 'learniq', 'appVersion' => '1.5.0', 'recordedAt' => '', 'conflicts' => [['path' => 'required', 'shipped' => ['a'], 'live' => ['b']]]]
		);
		$conflicts->expects($this->never())->method('accept');

		$response = $this->controller(conflicts: $conflicts)->conflicts(id: 'learner-profile');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('required', $response->getData()['conflicts'][0]['path']);
	}//end testTheListAnswersThePendingConflicts()

	/**
	 * A refused acceptance is 409 with the reason; an applied one is 200.
	 *
	 * @return void
	 */
	public function testARefusedAcceptanceIsAConflictAndAnAppliedOneIsOk(): void {
		$conflicts = $this->createMock(ShippedConflictServiceInterface::class);
		$conflicts->expects($this->exactly(2))->method('accept')->willReturnOnConsecutiveCalls(
			['applied' => false, 'reason' => 'name one conflicting part', 'schema' => 'learner-profile', 'schemaId' => 329, 'path' => '', 'from' => null, 'to' => null],
			['applied' => true, 'reason' => '', 'schema' => 'learner-profile', 'schemaId' => 329, 'path' => 'required', 'from' => ['b'], 'to' => ['a']]
		);

		$controller = $this->controller(conflicts: $conflicts);
		$refused = $controller->accept(id: 'learner-profile');
		$this->assertSame(409, $refused->getStatus());
		$this->assertSame('name one conflicting part', $refused->getData()['reason']);

		$applied = $controller->accept(id: 'learner-profile', path: 'required');
		$this->assertSame(200, $applied->getStatus());
		$this->assertSame(['a'], $applied->getData()['to']);
	}//end testARefusedAcceptanceIsAConflictAndAnAppliedOneIsOk()

	/**
	 * An unknown schema is 404 on both routes.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaIsNotFound(): void {
		$conflicts = $this->createMock(ShippedConflictServiceInterface::class);
		$conflicts->method('pending')->willThrowException(new DoesNotExistException('none'));
		$conflicts->method('accept')->willThrowException(new DoesNotExistException('none'));

		$controller = $this->controller(conflicts: $conflicts);
		$this->assertSame(404, $controller->conflicts(id: 'nope')->getStatus());
		$this->assertSame(404, $controller->accept(id: 'nope', path: 'required')->getStatus());
	}//end testAnUnknownSchemaIsNotFound()
}//end class
