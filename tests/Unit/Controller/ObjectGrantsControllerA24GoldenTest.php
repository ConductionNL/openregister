<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\ObjectGrantsController}.
 *
 * Pins every allowed and refused answer (status and body) of the grants list,
 * and that the read-grants gate is asked before the report is read.
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
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Controller\ObjectGrantsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\AccessReviewGate;
use OCA\OpenRegister\Service\Rbac\ObjectGrantsReport;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectGrantsControllerFactory;

/**
 * Golden outcomes of the grants list.
 */
class ObjectGrantsControllerA24GoldenTest extends TestCase {

	/**
	 * Report mock.
	 *
	 * @var ObjectGrantsReport&MockObject
	 */
	private ObjectGrantsReport&MockObject $report;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->report = $this->createMock(ObjectGrantsReport::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(bool $readable, bool $mayRead): ObjectGrantsController {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$gate = $this->createMock(AccessReviewGate::class);
		$gate->method('resolve')->with(new ObjectRef('r', 's', 'case-1'))->willReturn(null);
		if ($readable === true) {
			$gate = $this->createMock(AccessReviewGate::class);
			$gate->method('resolve')->with(new ObjectRef('r', 's', 'case-1'))->willReturn($object);
			$gate->expects($this->atLeastOnce())->method('mayReadGrants')->willReturn($mayRead);
		} else {
			$gate->expects($this->never())->method('mayReadGrants');
		}

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('getRegister')->willReturn(1);
		$objects->method('getSchema')->willReturn(7);

		return ObjectGrantsControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			objectService: $objects,
			gate: $gate,
			report: $this->report,
			logger: $this->logger
		);
	}

	private function assertOutcome(int $status, mixed $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	public function testUnreadableObjectIsNotFoundBeforeTheReport(): void {
		$this->report->expects($this->never())->method($this->anything());

		$this->assertOutcome(404, ['message' => 'Object not found'], $this->controller(false, true)->index('r', 's', 'case-1'));
	}

	public function testCallerWithoutTheRightIsForbiddenBeforeTheReport(): void {
		$this->report->expects($this->never())->method($this->anything());

		$this->assertOutcome(
			403,
			['message' => 'Reading who has access to this object needs the right to read its grants'],
			$this->controller(true, false)->index('r', 's', 'case-1', 'user')
		);
	}

	public function testPrincipalTypesAreSplitAndTrimmed(): void {
		$this->report->expects($this->exactly(2))->method('rowsFor')
			->willReturnCallback(fn (ObjectEntity $object, mixed $register, mixed $schema, array $types): array => [$register, $schema, $types]);
		$controller = $this->controller(true, true);

		$this->assertOutcome(200, [1, 7, ['user', 'group']], $controller->index('r', 's', 'case-1', ' user, ,group,'));
		$this->assertOutcome(200, [1, 7, []], $controller->index('r', 's', 'case-1'));
	}

	public function testUnreadableSourceIsAFiveHundred(): void {
		$this->report->method('rowsFor')->willThrowException(new \RuntimeException('shares'));
		$this->logger->expects($this->once())->method('error')->with(
			'[ObjectGrantsController] The grants of {uuid} could not be listed: {error}',
			$this->callback(fn (array $context): bool => $context['uuid'] === 'case-1' && $context['error'] === 'shares')
		);

		$this->assertOutcome(500, ['message' => 'The grants of this object could not be listed'], $this->controller(true, true)->index('r', 's', 'case-1'));
	}
}
