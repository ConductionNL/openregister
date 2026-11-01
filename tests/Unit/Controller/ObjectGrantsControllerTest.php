<?php

/**
 * The grants read: its order of checks and its failure answer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectGrantsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\AccessReviewGate;
use OCA\OpenRegister\Service\Rbac\ObjectGrantsReport;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tasks 3.1, 3.2 and the 404 and 403 halves of 4.4.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectGrantsController
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectGrantsControllerTest extends TestCase {

	/**
	 * The report; a refused caller must never reach it.
	 *
	 * @var ObjectGrantsReport&MockObject
	 */
	private ObjectGrantsReport&MockObject $report;

	/**
	 * A controller whose gate answers as given.
	 *
	 * @param bool                 $readable Whether the caller can read the record.
	 * @param bool                 $mayRead  Whether the caller may read its grants.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return ObjectGrantsController The controller.
	 */
	private function controllerFor(bool $readable, bool $mayRead, ?LoggerInterface $logger = null): ObjectGrantsController {
		$object = new ObjectEntity();
		$object->setUuid('case-1');

		$gate = $this->createMock(AccessReviewGate::class);
		$gate->method('resolve')->willReturn($readable === true ? $object : null);
		$gate->method('mayReadGrants')->willReturn($mayRead);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getRegister')->willReturn(1);
		$objectService->method('getSchema')->willReturn(7);

		$this->report = $this->createMock(ObjectGrantsReport::class);

		return new ObjectGrantsController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			objectService: $objectService,
			gate: $gate,
			report: $this->report,
			logger: ($logger ?? new NullLogger())
		);
	}//end controllerFor()

	/**
	 * A caller who cannot read the record learns nothing, not even that it exists.
	 *
	 * @return void
	 */
	public function testACallerWhoCannotReadTheRecordGets404(): void {
		$controller = $this->controllerFor(readable: false, mayRead: true);
		$this->report->expects($this->never())->method('rowsFor');

		$this->assertSame(404, $controller->index('dossiq', 'case', 'case-1')->getStatus());
	}//end testACallerWhoCannotReadTheRecordGets404()

	/**
	 * 🔴 A reader without the right is refused before any grant is read.
	 *
	 * @return void
	 */
	public function testAReaderWithoutTheRightGets403AndNothingIsRead(): void {
		$controller = $this->controllerFor(readable: true, mayRead: false);
		$this->report->expects($this->never())->method('rowsFor');

		$response = $controller->index('dossiq', 'case', 'case-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			'Reading who has access to this object needs the right to read its grants',
			$response->getData()['message']
		);
	}//end testAReaderWithoutTheRightGets403AndNothingIsRead()

	/**
	 * An admitted caller gets the rows, with the principal type filter passed on.
	 *
	 * @return void
	 */
	public function testAnAdmittedCallerGetsTheRows(): void {
		$controller = $this->controllerFor(readable: true, mayRead: true);
		$rows = ['object' => 'case-1', 'results' => [], 'denyEnforcement' => 'staging'];
		$this->report->expects($this->once())
			->method('rowsFor')
			->with($this->isInstanceOf(ObjectEntity::class), 1, 7, ['user', 'group'])
			->willReturn($rows);

		$response = $controller->index('dossiq', 'case', 'case-1', ' user, group ,');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($rows, $response->getData());
	}//end testAnAdmittedCallerGetsTheRows()

	/**
	 * No filter keeps every type.
	 *
	 * @return void
	 */
	public function testNoFilterKeepsEveryType(): void {
		$controller = $this->controllerFor(readable: true, mayRead: true);
		$this->report->expects($this->once())
			->method('rowsFor')
			->with($this->anything(), $this->anything(), $this->anything(), [])
			->willReturn(['object' => 'case-1', 'results' => [], 'denyEnforcement' => 'off']);

		$this->assertSame(200, $controller->index('dossiq', 'case', 'case-1')->getStatus());
	}//end testNoFilterKeepsEveryType()

	/**
	 * A source that cannot be read answers 500 with a logged cause, never an empty list.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceAnswers500AndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$controller = $this->controllerFor(readable: true, mayRead: true, logger: $logger);
		$this->report->method('rowsFor')->willThrowException(new \RuntimeException('share table gone'));

		$response = $controller->index('dossiq', 'case', 'case-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'The grants of this object could not be listed'], $response->getData());
	}//end testAnUnreadableSourceAnswers500AndIsLogged()
}//end class
