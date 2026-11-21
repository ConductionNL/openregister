<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\ObjectPermissionsController}.
 *
 * Pins every allowed and refused answer (status and body) of the access-set
 * endpoints, and that the review gate is asked before the report is read.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Controller\ObjectPermissionsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\AccessReviewGate;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReport;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectPermissionsControllerFactory;

/**
 * Golden outcomes of the access-set endpoints.
 */
class ObjectPermissionsControllerA24GoldenTest extends TestCase {

	/**
	 * Report mock.
	 *
	 * @var ObjectAccessReport&MockObject
	 */
	private ObjectAccessReport&MockObject $report;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->report = $this->createMock(ObjectAccessReport::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(bool $readable, bool $mayReview): ObjectPermissionsController {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$gate = $this->createMock(AccessReviewGate::class);
		$gate->method('resolve')->with(new ObjectRef('r', 's', 'case-1'))->willReturn(null);
		if ($readable === true) {
			$gate = $this->createMock(AccessReviewGate::class);
			$gate->method('resolve')->with(new ObjectRef('r', 's', 'case-1'))->willReturn($object);
			$gate->expects($this->atLeastOnce())->method('mayReviewAccess')->willReturn($mayReview);
		} else {
			$gate->expects($this->never())->method('mayReviewAccess');
		}

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('getRegister')->willReturn(1);
		$objects->method('getSchema')->willReturn(7);

		return ObjectPermissionsControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			objectService: $objects,
			report: $this->report,
			gate: $gate,
			logger: $this->logger
		);
	}

	private function assertOutcome(int $status, mixed $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	public function testUnreadableObjectIsNotFoundBeforeTheReport(): void {
		$this->report->expects($this->never())->method($this->anything());
		$controller = $this->controller(false, true);

		$this->assertOutcome(404, ['message' => 'Object not found'], $controller->index('r', 's', 'case-1'));
		$this->assertOutcome(404, ['message' => 'Object not found'], $controller->history('r', 's', 'case-1', null));
	}

	public function testReaderWithoutManageIsForbiddenBeforeTheReport(): void {
		$this->report->expects($this->never())->method($this->anything());
		$controller = $this->controller(true, false);
		$refusal = ['message' => 'Reading who holds rights on this object needs the manage permission'];

		$this->assertOutcome(403, $refusal, $controller->index('r', 's', 'case-1'));
		$this->assertOutcome(403, $refusal, $controller->history('r', 's', 'case-1', '2026-01-01'));
	}

	public function testIndexOutcomes(): void {
		$this->report->method('setFor')->with($this->anything(), new RegisterSchemaRef(1, 7))->willReturnOnConsecutiveCalls(
			['object' => 'case-1', 'holders' => []],
			$this->throwException(new \RuntimeException('broken'))
		);
		$controller = $this->controller(true, true);

		$this->assertOutcome(200, ['object' => 'case-1', 'holders' => []], $controller->index('r', 's', 'case-1'));
		$this->logger->expects($this->once())->method('error')->with(
			'[ObjectPermissionsController] The access set for {uuid} could not be assembled: {error}',
			$this->callback(fn (array $context): bool => $context['uuid'] === 'case-1' && $context['error'] === 'broken')
		);
		$this->assertOutcome(500, ['message' => 'The access set for this object could not be assembled'], $controller->index('r', 's', 'case-1'));
	}

	public function testHistoryOutcomes(): void {
		$this->report->method('historyFor')->with($this->anything(), 'm')->willReturnOnConsecutiveCalls(
			['changes' => []],
			$this->throwException(new \RuntimeException('trail'))
		);
		$controller = $this->controller(true, true);

		$this->assertOutcome(200, ['changes' => []], $controller->history('r', 's', 'case-1', 'm'));
		$this->logger->expects($this->never())->method('error');
		$this->assertOutcome(500, ['message' => 'The audit trail for this object could not be read'], $controller->history('r', 's', 'case-1', 'm'));
	}
}
