<?php

declare(strict_types=1);

/**
 * ArchivalNominationListener tests.
 *
 * The listener's whole job is deciding WHEN to nominate: on a state the schema
 * calls final, and on no other. A listener that nominated on every transition
 * would rewrite a record's archival future every time somebody moved it along.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Listener\ArchivalNominationListener;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ArchivalNominationListener.
 */
class ArchivalNominationListenerTest extends TestCase {

	private ArchivalNominationService&MockObject $nominations;
	private MagicMapper&MockObject $objectMapper;
	private LoggerInterface&MockObject $logger;
	private ArchivalNominationListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->nominations = $this->createMock(ArchivalNominationService::class);
		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update'])
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);

		$schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemaMapper->method('find')->willReturn(
			$this->getMockBuilder(Schema::class)->disableOriginalConstructor()->getMock()
		);

		$this->listener = new ArchivalNominationListener(
			$this->nominations,
			$schemaMapper,
			$this->objectMapper,
			$this->logger
		);
	}

	/**
	 * A transition event onto one state.
	 *
	 * @param string $to The state reached.
	 *
	 * @return ObjectTransitionedEvent The event.
	 */
	private function event(string $to): ObjectTransitionedEvent {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');

		return new ObjectTransitionedEvent(
			$object,
			'afhandelen',
			'in_behandeling',
			$to,
			'els',
			'zaken',
			'zaak'
		);
	}

	public function testReachingAFinalStateNominatesAndPersists(): void {
		$this->nominations->method('isTerminalState')->willReturn(true);
		$this->nominations->expects($this->once())
			->method('nominate')
			->willReturn(['status' => ArchivalNominationService::STATUS_NOMINATED]);
		$this->objectMapper->expects($this->once())->method('update');

		$this->listener->handle($this->event('afgehandeld'));
	}

	public function testAnOrdinaryTransitionNominatesNothing(): void {
		$this->nominations->method('isTerminalState')->willReturn(false);
		$this->nominations->expects($this->never())->method('nominate');
		$this->objectMapper->expects($this->never())->method('update');

		$this->listener->handle($this->event('in_behandeling'));
	}

	public function testASchemaThatDoesNotArchiveIsNotWrittenBack(): void {
		$this->nominations->method('isTerminalState')->willReturn(true);
		$this->nominations->method('nominate')->willReturn(
			['status' => ArchivalNominationService::STATUS_NOT_APPLICABLE]
		);
		$this->objectMapper->expects($this->never())->method('update');

		$this->listener->handle($this->event('afgehandeld'));
	}

	/**
	 * The transition already happened by the time this runs. Losing the
	 * nomination is said out loud; throwing out of here would not undo the
	 * closure and would surface as a failed transition the user did complete.
	 */
	public function testAFailedNominationIsReportedAndSwallowed(): void {
		$this->nominations->method('isTerminalState')->willReturn(true);
		$this->nominations->method('nominate')->willThrowException(new RuntimeException('selectielijst unreachable'));

		$this->logger->expects($this->once())->method('error');

		$this->listener->handle($this->event('afgehandeld'));
	}
}
