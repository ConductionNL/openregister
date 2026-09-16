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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\Appraisal;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\RecordState;
use OCA\OpenRegister\Service\Lifecycle\LifecycleFinalStateResolver;
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
	private MagicMapper&MockObject $objects;
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
		$this->objects = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAcrossAllSources'])
			->getMock();

		$this->service = new ArchivalNominationService(
			$this->retentionService,
			$this->logger,
			new LifecycleFinalStateResolver($this->objects, $this->logger)
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

	// ------------------------------------------------------------------
	// The dossiq shape: a status that is a `$ref`, and archiving declared
	// through `x-openregister-archival` rather than the `archive` column.
	// Neither half reached the other, and both failures were silent.
	// ------------------------------------------------------------------

	/**
	 * A statusType row as the object lookup hands it back.
	 *
	 * @param bool $isFinal Whether the row says the case has ended.
	 *
	 * @return array{object: ObjectEntity, register: null, schema: Schema} The lookup result.
	 */
	private function statusRow(bool $isFinal): array {
		$row = new ObjectEntity();
		$row->setUuid('status-uuid');
		$row->setObject(['name' => 'Afgehandeld', 'isFinal' => $isFinal]);

		// A real entity: `getSlug()` is Entity magic, so a double could only
		// ADD it, and an added method can never disagree with the real class.
		$schema = new Schema();
		$schema->setId(7);
		$schema->setUuid('statustype-schema-uuid');
		$schema->setSlug('statusType');
		$schema->setTitle('Status Type');

		return ['object' => $row, 'register' => null, 'schema' => $schema];
	}

	/**
	 * The dossiq case lifecycle annotation, `final` in the reference form.
	 *
	 * @return array<string, mixed> The schema configuration.
	 */
	private function caseLifecycle(): array {
		return [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
				'final' => ['from' => 'statusType', 'field' => 'isFinal'],
				'provider' => 'OCA\\Dossiq\\Lifecycle\\CaseActionProvider',
			],
		];
	}

	/**
	 * A per-instance uuid is what reaches isTerminalState() when the lifecycle
	 * field is a `$ref`, and the referenced row is the only thing that knows.
	 *
	 * @return void
	 */
	public function testAReferencedStatusRowDecidesWhetherTheCaseHasEnded(): void {
		$this->objects->method('findAcrossAllSources')->willReturn($this->statusRow(true));

		$this->assertTrue(
			$this->service->isTerminalState(
				schema: $this->schema([], $this->caseLifecycle()),
				state: 'status-uuid'
			)
		);
	}

	public function testAReferencedStatusRowThatIsNotFinalDoesNotClose(): void {
		$this->objects->method('findAcrossAllSources')->willReturn($this->statusRow(false));

		$this->assertFalse(
			$this->service->isTerminalState(
				schema: $this->schema([], $this->caseLifecycle()),
				state: 'status-uuid'
			)
		);
	}

	/**
	 * The static list still works, and it is not read as a reference.
	 *
	 * @return void
	 */
	public function testTheStaticListStillDecidesWhenThatIsWhatTheSchemaWrote(): void {
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$schema = $this->schema(
			[],
			['x-openregister-lifecycle' => ['field' => 'status', 'final' => ['afgehandeld']]]
		);

		$this->assertTrue($this->service->isTerminalState(schema: $schema, state: 'afgehandeld'));
		$this->assertFalse($this->service->isTerminalState(schema: $schema, state: 'status-uuid'));
	}

	/**
	 * The dossiq case declares `x-openregister-archival` and no `archive`
	 * column. It answered `not_applicable`, so a closed case had no archival
	 * future and nothing said so.
	 *
	 * @return void
	 */
	public function testASchemaDeclaringRetentionTheVocabularyWayIsNominated(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(null);
		$this->retentionService->method('calculateArchiveActionDate')->willReturn('2036-03-01');

		$object = $this->object();
		$object->setObject(['caseType' => 'wmo-melding']);

		$nomination = $this->service->nominate(
			object: $object,
			schema: $this->schema(
				[],
				[
					'x-openregister-archival' => [
						'retention' => [
							'default' => 'P10Y',
							'rules' => [
								['condition' => 'caseType == "subsidie-verlening"', 'retention' => 'P20Y'],
							],
						],
					],
				]
			),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::STATUS_NOMINATED, $nomination['status']);
		$this->assertSame(ArchivalNominationService::RULE_ARCHIVAL_ANNOTATION, $nomination['rule']);
		$this->assertSame(Appraisal::DESTROY, $object->getRetention()['archiefnominatie']);
		$this->assertSame('P10Y', $object->getRetention()['bewaartermijn']);
	}

	/**
	 * The retention a matched rule gives THIS row is the period, which is why
	 * the object is read and a schema-wide translation would be wrong.
	 *
	 * @return void
	 */
	public function testAMatchedRetentionRuleGivesThisRecordItsOwnPeriod(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(null);

		$captured = null;
		$this->retentionService->method('calculateArchiveActionDate')
			->willReturnCallback(
				static function ($object, $schema, string $retentionPeriod) use (&$captured): string {
					$captured = $retentionPeriod;
					return '2046-03-01';
				}
			);

		$object = $this->object();
		$object->setObject(['caseType' => 'subsidie-verlening']);

		$this->service->nominate(
			object: $object,
			schema: $this->schema(
				[],
				[
					'x-openregister-archival' => [
						'retention' => [
							'default' => 'P10Y',
							'rules' => [
								['condition' => 'caseType == "subsidie-verlening"', 'retention' => 'P20Y'],
							],
						],
					],
				]
			),
			trigger: 'closure'
		);

		$this->assertSame('P20Y', $captured);
	}

	/**
	 * A filled-in `archive` column is the more specific statement, so it wins.
	 *
	 * @return void
	 */
	public function testTheArchiveColumnStillWinsWhenItIsFilledIn(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(null);
		$this->retentionService->method('calculateArchiveActionDate')->willReturn('2031-03-01');

		$object = $this->object();
		$nomination = $this->service->nominate(
			object: $object,
			schema: $this->schema(
				['enabled' => true, 'defaultNominatie' => 'blijvend_bewaren', 'defaultBewaartermijn' => 'P5Y'],
				['x-openregister-archival' => ['retention' => ['default' => 'P10Y']]]
			),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::RULE_SCHEMA_DEFAULT, $nomination['rule']);
		$this->assertSame('blijvend_bewaren', $object->getRetention()['archiefnominatie']);
	}

	/**
	 * An annotation that names its own appraisal is believed: several apps
	 * already ship `action`, and ignoring it would nominate for destruction a
	 * record its own schema says to keep.
	 *
	 * @return void
	 */
	public function testAnAnnotationThatNamesItsOwnAppraisalIsBelieved(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(null);
		$this->retentionService->method('calculateArchiveActionDate')->willReturn(null);

		$object = $this->object();
		$this->service->nominate(
			object: $object,
			schema: $this->schema(
				[],
				[
					'x-openregister-archival' => [
						'retention' => ['default' => 'P20Y'],
						'action' => 'blijvend_bewaren',
					],
				]
			),
			trigger: 'closure'
		);

		$this->assertSame(Appraisal::RETAIN_PERMANENTLY, $object->getRetention()['archiefnominatie']);
	}

	/**
	 * "Not applicable" and "we looked in one of the two places" read identically
	 * from the outside, and the second is the bug. So the answer names both.
	 *
	 * @return void
	 */
	public function testNotApplicableNamesBothPlacesItLooked(): void {
		$nomination = $this->service->nominate(
			object: $this->object(),
			schema: $this->schema([], []),
			trigger: 'closure'
		);

		$this->assertSame(ArchivalNominationService::STATUS_NOT_APPLICABLE, $nomination['status']);
		$this->assertStringContainsString('`archive` block', $nomination['unnominatableReason']);
		$this->assertStringContainsString('x-openregister-archival', $nomination['unnominatableReason']);
	}

	/**
	 * An unnominatable record names both places a default could have lived, so
	 * a reader is not sent to the `archive` block alone.
	 *
	 * @return void
	 */
	public function testAnUnnominatableRecordNamesBothPlacesADefaultCouldLive(): void {
		$this->retentionService->method('lookupSelectielijstEntry')->willReturn(null);

		$nomination = $this->service->nominate(
			object: $this->object(),
			schema: $this->schema(['enabled' => true, 'classification' => '11.1.2']),
			trigger: 'closure'
		);

		$this->assertStringContainsString('`archive` block', $nomination['unnominatableReason']);
		$this->assertStringContainsString('x-openregister-archival', $nomination['unnominatableReason']);
	}

	/**
	 * A graph block already names the sibling schema and the property that
	 * marks the last state, so a graph-mode schema gets the same answer without
	 * declaring `final` twice.
	 *
	 * @return void
	 */
	public function testAGraphBlockAlreadyNamesTheEndAndIsReadThere(): void {
		$this->objects->method('findAcrossAllSources')->willReturn($this->statusRow(true));

		$schema = $this->schema(
			[],
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'graph' => [
						'schema' => 'statusType',
						'parentField' => 'caseType',
						'parentFrom' => 'caseType',
						'orderField' => 'order',
						'finalField' => 'isFinal',
						'allowedMoves' => 'forward',
					],
				],
			]
		);

		$this->assertTrue($this->service->isTerminalState(schema: $schema, state: 'status-uuid'));
	}

	/**
	 * An author who wrote `final` meant it, so it wins over the graph block.
	 *
	 * @return void
	 */
	public function testAnExplicitFinalWinsOverTheGraphBlock(): void {
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$schema = $this->schema(
			[],
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'final' => ['afgehandeld'],
					'graph' => ['schema' => 'statusType', 'finalField' => 'isFinal'],
				],
			]
		);

		$this->assertTrue($this->service->isTerminalState(schema: $schema, state: 'afgehandeld'));
		$this->assertFalse($this->service->isTerminalState(schema: $schema, state: 'status-uuid'));
	}
}
