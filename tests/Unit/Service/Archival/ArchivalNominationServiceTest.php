<?php

declare(strict_types=1);

/**
 * ArchivalNominationService tests.
 *
 * Pins what happens when a record's business use ends: the selectielijst row
 * wins, the schema default catches what it does not cover, a local override
 * takes the period, and a record nothing can decide is REPORTED with the
 * missing source named rather than left quietly without an archival future.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\RecordState;
use OCA\OpenRegister\Service\RetentionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ArchivalNominationService.
 */
class ArchivalNominationServiceTest extends TestCase {

	private RetentionService&MockObject $retentionService;
	private LoggerInterface&MockObject $logger;
	private ArchivalNominationService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->retentionService = $this->getMockBuilder(RetentionService::class)
			->disableOriginalConstructor()
			// onlyMethods, never addMethods: a double that invents a method the
			// real class lacks can only ever pass.
			->onlyMethods(['lookupSelectielijstEntry', 'calculateArchiveActionDate'])
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ArchivalNominationService(
			$this->retentionService,
			$this->logger
		);
	}

	/**
	 * A schema with an archive block and an optional lifecycle annotation.
	 *
	 * @param array<string, mixed> $archive       The archive block.
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return Schema&MockObject The schema.
	 */
	private function schema(array $archive, array $configuration = []): Schema {
		$schema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getArchive', 'getConfiguration'])
			->getMock();
		$schema->method('getArchive')->willReturn($archive);
		$schema->method('getConfiguration')->willReturn($configuration);

		return $schema;
	}

	/**
	 * A record.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');

		return $object;
	}

	public function testAStateTheSchemaCallsFinalIsTerminal(): void {
		$schema = $this->schema(
			[],
			['x-openregister-lifecycle' => ['field' => 'status', 'final' => ['afgehandeld', 'ingetrokken']]]
		);

		$this->assertTrue($this->service->isTerminalState(schema: $schema, state: 'afgehandeld'));
		$this->assertFalse($this->service->isTerminalState(schema: $schema, state: 'in_behandeling'));
		$this->assertFalse($this->service->isTerminalState(schema: $schema, state: ''));
	}

	public function testASchemaWithNoLifecycleAnnotationHasNoTerminalState(): void {
		$this->assertFalse(
			$this->service->isTerminalState(schema: $this->schema([], []), state: 'afgehandeld')
		);
	}

	public function testClosingAnObjectWritesItsArchivalFuture(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(
			[
				'categorie' => '11.1.2',
				'archiefnominatie' => 'vernietigen',
				'bewaartermijn' => 'P7Y',
				'bron' => 'Selectielijst gemeenten 2020',
			]
		);
		$this->retentionService->method('calculateArchiveActionDate')->willReturn('2033-03-01');

		$object = $this->object();
		$nomination = $this->service->nominate(
			object: $object,
			schema: $this->schema(['enabled' => true, 'classification' => '11.1.2']),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::STATUS_NOMINATED, $nomination['status']);
		$this->assertSame(ArchivalNominationService::RULE_SELECTION_LIST, $nomination['rule']);
		$this->assertSame('11.1.2', $nomination['selectionListRow']);

		$retention = $object->getRetention();
		$this->assertSame('vernietigen', $retention['archiefnominatie']);
		$this->assertSame('2033-03-01', $retention['archiefactiedatum']);
		$this->assertSame('P7Y', $retention['bewaartermijn']);
		$this->assertSame('11.1.2', $retention['selectielijstRow']);
		$this->assertSame('Selectielijst gemeenten 2020', $retention['selectielijstBron']);
		$this->assertSame(RecordState::SEMI_STATIC, $retention['archiefstatus']);
	}

	public function testTheSchemaDefaultDecidesWhenNoSelectielijstRowMatches(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(null);
		$this->retentionService->method('calculateArchiveActionDate')->willReturn('2031-03-01');

		$object = $this->object();
		$nomination = $this->service->nominate(
			object: $object,
			schema: $this->schema(
				[
					'enabled' => true,
					'classification' => '11.1.2',
					'defaultNominatie' => 'vernietigen',
					'defaultBewaartermijn' => 'P5Y',
				]
			),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::RULE_SCHEMA_DEFAULT, $nomination['rule']);
		$this->assertArrayNotHasKey('selectionListRow', $nomination);
	}

	public function testALocalOverrideTakesThePeriodAndSaysSo(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(
			['archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P7Y', 'categorie' => '11.1.2']
		);

		$captured = null;
		$this->retentionService->method('calculateArchiveActionDate')
			->willReturnCallback(
				static function ($object, $schema, string $retentionPeriod) use (&$captured): string {
					$captured = $retentionPeriod;
					return '2036-03-01';
				}
			);

		$nomination = $this->service->nominate(
			object: $this->object(),
			schema: $this->schema(
				['enabled' => true, 'classification' => '11.1.2', 'bewaartermijnOverride' => 'P10Y']
			),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::RULE_LOCAL_OVERRIDE, $nomination['rule']);
		$this->assertSame('P10Y', $captured);
	}

	public function testAnObjectNobodyCanNominateIsReportedWithTheMissingSourceNamed(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(null);

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('cannot be nominated'));

		$object = $this->object();
		$nomination = $this->service->nominate(
			object: $object,
			schema: $this->schema(['enabled' => true, 'classification' => '11.1.2']),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::STATUS_UNNOMINATABLE, $nomination['status']);
		$this->assertStringContainsString('no selectielijst row matches category 11.1.2', $nomination['unnominatableReason']);

		// Reported ON the record, not merely logged: an unnominatable object
		// and one nobody has got to yet look identical from an absent appraisal.
		$retention = $object->getRetention();
		$this->assertSame(
			ArchivalNominationService::STATUS_UNNOMINATABLE,
			$retention['nomination']['status']
		);
		$this->assertArrayNotHasKey('archiefnominatie', $retention);
	}

	public function testASchemaThatNamesNoCategoryAtAllSaysThat(): void {
		$nomination = $this->service->nominate(
			object: $this->object(),
			schema: $this->schema(['enabled' => true]),
			trigger: 'closure'
		);

		$this->assertStringContainsString(
			'names no selectielijst category',
			$nomination['unnominatableReason']
		);
	}

	public function testARetentionPeriodThatCannotBeCountedIsUnnominatable(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(
			['archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P7Y', 'categorie' => '11.1.2']
		);
		$this->retentionService->method('calculateArchiveActionDate')->willReturn(null);

		$nomination = $this->service->nominate(
			object: $this->object(),
			schema: $this->schema(['enabled' => true, 'classification' => '11.1.2']),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::STATUS_UNNOMINATABLE, $nomination['status']);
		$this->assertStringContainsString('could not be counted', $nomination['unnominatableReason']);
	}

	/**
	 * A record kept forever has no disposal date to count, and demanding one
	 * would make every permanently preserved dossier unnominatable.
	 */
	public function testAPermanentlyPreservedRecordNominatesWithoutADisposalDate(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(
			['archiefnominatie' => 'blijvend_bewaren', 'bewaartermijn' => 'P10Y', 'categorie' => '1.1']
		);
		$this->retentionService->method('calculateArchiveActionDate')->willReturn(null);

		$object = $this->object();
		$nomination = $this->service->nominate(
			object: $object,
			schema: $this->schema(['enabled' => true, 'classification' => '1.1']),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::STATUS_NOMINATED, $nomination['status']);
		$this->assertSame('blijvend_bewaren', $object->getRetention()['archiefnominatie']);
	}

	public function testASchemaWithArchivingOffIsNotApplicable(): void {
		$object = $this->object();

		$this->assertSame(
			ArchivalNominationService::STATUS_NOT_APPLICABLE,
			$this->service->nominate(
				object: $object,
				schema: $this->schema(['enabled' => false]),
				trigger: 'closure'
			)['status']
		);

		// Nothing was written at all, not even an empty nomination block.
		$this->assertSame([], ($object->getRetention() ?? []));
	}

	public function testRecomputingRecordsTheActorAndTheReasonAndKeepsWhatItReplaced(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(
			['archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P7Y', 'categorie' => '11.1.2']
		);
		$this->retentionService->method('calculateArchiveActionDate')->willReturn('2033-03-01');

		$schema = $this->schema(['enabled' => true, 'classification' => '11.1.2']);
		$object = $this->object();

		$this->service->nominate(object: $object, schema: $schema, trigger: 'closure');
		$this->service->nominate(
			object: $object,
			schema: $schema,
			trigger: 'recompute',
			actor: 'els',
			reason: 'Selectielijst 2026 replaced the 2020 list'
		);

		$retention = $object->getRetention();
		$this->assertCount(2, $retention['nominationHistory']);
		$this->assertSame('closure', $retention['nominationHistory'][0]['trigger']);
		$this->assertSame('recompute', $retention['nominationHistory'][1]['trigger']);
		$this->assertSame('els', $retention['nominationHistory'][1]['actor']);
		$this->assertSame(
			'Selectielijst 2026 replaced the 2020 list',
			$retention['nominationHistory'][1]['reason']
		);
		$this->assertSame('recompute', $retention['nomination']['trigger']);
	}
}
