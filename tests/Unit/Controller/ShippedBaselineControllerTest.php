<?php

/**
 * The admin routes for the reset to the shipped baseline.
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
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ShippedBaselineController;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * GET previews, POST applies; a refusal is 409 with its reason, an unknown schema 404.
 */
class ShippedBaselineControllerTest extends TestCase {

	/**
	 * The controller over a reset service double.
	 *
	 * @param ShippedBaselineResetService $reset The service.
	 *
	 * @return ShippedBaselineController
	 */
	private function controller(ShippedBaselineResetService $reset): ShippedBaselineController {
		return new ShippedBaselineController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			reset: $reset
		);
	}//end controller()

	/**
	 * The preview passes the schema and the part through and answers 200.
	 *
	 * @return void
	 */
	public function testThePreviewAnswersWhatWouldChange(): void {
		$reset = $this->createMock(ShippedBaselineResetService::class);
		$reset->expects($this->once())->method('preview')->with('learner-profile', 'authorization.read')->willReturn(
			['applicable' => true, 'reason' => '', 'schema' => 'learner-profile', 'schemaId' => 329, 'path' => 'authorization.read', 'from' => ['hr'], 'to' => ['hr', 'x']]
		);
		$reset->expects($this->never())->method('reset');

		$response = $this->controller(reset: $reset)->preview(id: 'learner-profile', path: 'authorization.read');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['hr', 'x'], $response->getData()['to']);
	}//end testThePreviewAnswersWhatWouldChange()

	/**
	 * A refused reset is 409 with the reason; an applied one is 200.
	 *
	 * @return void
	 */
	public function testARefusedResetIsAConflictAndAnAppliedOneIsOk(): void {
		$reset = $this->createMock(ShippedBaselineResetService::class);
		$reset->method('reset')->willReturnOnConsecutiveCalls(
			['applied' => false, 'reason' => 'name one part to reset', 'schema' => 'learner-profile', 'schemaId' => 329, 'path' => '', 'from' => null, 'to' => null],
			['applied' => true, 'reason' => '', 'schema' => 'learner-profile', 'schemaId' => 329, 'path' => 'authorization.read', 'from' => ['hr'], 'to' => ['hr', 'x']]
		);

		$controller = $this->controller(reset: $reset);
		$refused = $controller->reset(id: 'learner-profile', path: '');
		$this->assertSame(409, $refused->getStatus());
		$this->assertSame('name one part to reset', $refused->getData()['reason']);

		$applied = $controller->reset(id: 'learner-profile', path: 'authorization.read');
		$this->assertSame(200, $applied->getStatus());
		$this->assertTrue($applied->getData()['applied']);
	}//end testARefusedResetIsAConflictAndAnAppliedOneIsOk()

	/**
	 * An unknown schema is 404 on both routes.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaIsNotFound(): void {
		$reset = $this->createMock(ShippedBaselineResetService::class);
		$reset->method('preview')->willThrowException(new DoesNotExistException('none'));
		$reset->method('reset')->willThrowException(new DoesNotExistException('none'));

		$controller = $this->controller(reset: $reset);
		$this->assertSame(404, $controller->preview(id: 'nope', path: 'authorization.read')->getStatus());
		$this->assertSame(404, $controller->reset(id: 'nope', path: 'authorization.read')->getStatus());
	}//end testAnUnknownSchemaIsNotFound()
}//end class
