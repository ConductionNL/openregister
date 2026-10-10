<?php

/**
 * The contract consumers of the file freeze rely on (REQ-OAS-007, task W.5).
 *
 * opencatalogi (publication-withdrawal-aftercare) and dossiq
 * (woo-delivered-set-is-a-record) freeze an object and expect its files to
 * refuse writes with one 409 body. This pins both halves, so a rename here
 * breaks this test before it breaks them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Controller\ObjectStateController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\Object\ArchiveHandler;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The freeze route and the refusal body.
 */
class FrozenFileWriteContractTest extends TestCase {

	public function testTheFreezeRouteIsPostOnTheObject(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$freeze = array_values(array_filter(
			$routes['routes'],
			static fn (array $route): bool => $route['name'] === 'objectState#freeze'
		));

		$this->assertCount(1, $freeze);
		$this->assertSame('/api/objects/{register}/{schema}/{id}/freeze', $freeze[0]['url']);
		$this->assertSame('POST', $freeze[0]['verb']);
	}//end testTheFreezeRouteIsPostOnTheObject()

	public function testTheFreezeTakesAReasonAndAState(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['reason', null, 'Woo-levering'],
			['state', null, 'geleverd'],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dossiq-publicatie');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$handler = $this->createMock(ArchiveHandler::class);
		$handler->expects($this->once())->method('freeze')
			->with('obj-1', 'Woo-levering', 'geleverd', 'woo', 'wooDeliveredSet')
			->willReturn(['uuid' => 'obj-1', 'frozen' => ['state' => 'geleverd']]);

		$controller = new ObjectStateController('openregister', $request, $handler, $session);
		$response = $controller->freeze(register: 'woo', schema: 'wooDeliveredSet', id: 'obj-1');

		$this->assertSame(200, $response->getStatus());
	}//end testTheFreezeTakesAReasonAndAState()

	public function testAFileWriteRefusalIs409WithTheMarker(): void {
		$object = new ObjectEntity();
		$object->setFrozen(['by' => 'dossiq-publicatie', 'at' => '2026-10-10T09:00:00+00:00', 'reason' => 'Woo-levering', 'state' => 'geleverd']);

		$refusal = (new FileWriteGuard())->refusalFor(object: $object);

		$this->assertInstanceOf(ObjectStateWriteException::class, $refusal);
		$this->assertSame(409, ObjectStateWriteException::HTTP_STATUS);
		$this->assertSame(['error', 'state', 'by', 'at', 'reason'], array_keys($refusal->toResponseBody()));
	}//end testAFileWriteRefusalIs409WithTheMarker()
}//end class
