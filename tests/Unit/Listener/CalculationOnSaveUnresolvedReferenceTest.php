<?php

/**
 * OpenRegister CalculationOnSaveUnresolvedReferenceTest
 *
 * A calculation gives the same answer whoever saves, and never writes null
 * over a stored value because a reference could not be resolved. Runs the
 * real listener, payload builder, reference resolver, tenant guard and
 * evaluator; only ObjectService and the guard's two database reads are doubled.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\CalculationOnSaveListener;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Calculation\ReferenceTenantGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\SequenceService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The save-time listener keeps a stored value when a reference cannot be resolved.
 */
class CalculationOnSaveUnresolvedReferenceTest extends TestCase {

	/** @var ObjectService&MockObject */
	private $objectService;

	/** @var RuleRunRecorder&MockObject */
	private $ruleRuns;

	/**
	 * Every trace recorded, keyed by rule id.
	 *
	 * @var array<string, RuleTrace>
	 */
	private array $traces = [];

	private CalculationOnSaveListener $listener;

	/**
	 * Wire the real pipeline around a doubled ObjectService.
	 *
	 * The session double answers "no user": the save is an anonymous portal
	 * write, the context that used to resolve every reference empty.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);

		$this->objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll'])
			->getMock();

		$organisations = $this->getMockBuilder(OrganisationMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findParentChain'])
			->getMock();
		$organisations->method('findParentChain')->willReturn([]);
		$sharedMasterData = $this->getMockBuilder(SharedMasterDataService::class)
			->disableOriginalConstructor()
			->onlyMethods(['holdersForResource'])
			->getMock();
		$sharedMasterData->method('holdersForResource')->willReturn([]);

		$payloadBuilder = new CalculationPayloadBuilder(
			references: new ReferenceResolver(
				$this->objectService,
				new ReferenceTenantGuard($organisations, $sharedMasterData, $logger),
				$logger
			),
			aggregates: $this->createMock(AggregateReferenceResolver::class)
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->caseSchema());

		$this->ruleRuns = $this->getMockBuilder(RuleRunRecorder::class)
			->disableOriginalConstructor()
			->onlyMethods(['record'])
			->getMock();
		$this->ruleRuns->method('record')->willReturnCallback(
			function (string $ruleId, string $schemaSlug, RuleTrace $trace): void {
				$this->traces[$ruleId] = $trace;
			}
		);

		$this->listener = new CalculationOnSaveListener(
			$schemaMapper,
			$this->createMock(RegisterMapper::class),
			new CalculationEvaluator(new PlaceholderResolver($userSession)),
			$payloadBuilder,
			$this->createMock(SequenceService::class),
			$this->ruleRuns,
			$logger
		);
	}//end setUp()

	/**
	 * A slice of dossiq's case schema: one reference, two calculations reading it, one not.
	 *
	 * @return Schema The schema.
	 */
	private function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setId(244);
		$schema->setSlug('case');
		$schema->setProperties([]);
		$schema->setConfiguration(
			[
				'x-openregister-references' => [
					'caseType' => ['schema' => 'caseType', 'mode' => 'relatedObject', 'field' => 'caseType'],
				],
				'x-openregister-calculations' => [
					'statutoryTerm' => [
						'type' => 'string',
						'materialise' => true,
						'expression' => ['prop' => '@ref.caseType.processingDeadline'],
					],
					'deadline' => [
						'type' => 'date',
						'materialise' => true,
						'expression' => [
							'dateAdd' => [
								'date' => ['prop' => 'startDate'],
								'duration' => ['prop' => '@ref.caseType.processingDeadline'],
							],
						],
					],
					'title' => [
						'type' => 'string',
						'materialise' => true,
						'expression' => ['prop' => 'subject'],
					],
				],
			]
		);

		return $schema;
	}//end caseSchema()

	/**
	 * A case in organisation A with good stored calculated values.
	 *
	 * @return ObjectEntity The case.
	 */
	private function storedCase(): ObjectEntity {
		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$case->setRegister('22');
		$case->setSchema('244');
		$case->setOrganisation('org-a');
		$case->setObject(
			[
				'caseType' => 'ct-1',
				'subject' => 'Bezwaar parkeerboete',
				'startDate' => '2026-01-01',
				'statutoryTerm' => 'P8W',
				'deadline' => '2026-02-26',
				'title' => 'old title',
			]
		);

		return $case;
	}//end storedCase()

	/**
	 * A case type in the given organisation.
	 *
	 * @param string $organisation The case type's organisation.
	 *
	 * @return ObjectEntity The case type.
	 */
	private function caseType(string $organisation): ObjectEntity {
		$type = new ObjectEntity();
		$type->setUuid('ct-1');
		$type->setRegister('22');
		$type->setSchema('237');
		$type->setOrganisation($organisation);
		$type->setObject(['processingDeadline' => 'P6W']);

		return $type;
	}//end caseType()

	/**
	 * An anonymous save computes from the case type, read as the system.
	 *
	 * @return void
	 */
	public function testAnAnonymousSaveComputesFromTheReference(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('ct-1', $this->anything(), $this->anything(), '22', 'caseType', false, false)
			->willReturn($this->caseType(organisation: 'org-a'));
		$case = $this->storedCase();

		$this->listener->handle(new ObjectUpdatingEvent($case));

		$this->assertSame('P6W', $case->getObject()['statutoryTerm']);
		$this->assertSame('2026-02-12', substr((string)$case->getObject()['deadline'], 0, 10));
		$this->assertSame(RuleVocabulary::VERDICT_FIRED, $this->traces['calculation:case:statutoryTerm']->getVerdict());
	}//end testAnAnonymousSaveComputesFromTheReference()

	/**
	 * A case type in another tenant is refused, and the stored values stay.
	 *
	 * @return void
	 */
	public function testACrossTenantReferenceKeepsTheStoredValues(): void {
		$this->objectService->method('find')->willReturn($this->caseType(organisation: 'org-b'));
		$case = $this->storedCase();

		$this->listener->handle(new ObjectUpdatingEvent($case));

		$this->assertSame('P8W', $case->getObject()['statutoryTerm']);
		$this->assertSame('2026-02-26', $case->getObject()['deadline']);
	}//end testACrossTenantReferenceKeepsTheStoredValues()

	/**
	 * A reference that resolves empty keeps the stored value and says why.
	 *
	 * The calculation that does not read the reference still runs.
	 *
	 * @return void
	 */
	public function testAnUnresolvedReferenceKeepsTheStoredValueAndTracesIt(): void {
		$this->objectService->method('find')->willReturn(null);
		$case = $this->storedCase();

		$this->listener->handle(new ObjectUpdatingEvent($case));

		$data = $case->getObject();
		$this->assertSame('P8W', $data['statutoryTerm']);
		$this->assertSame('2026-02-26', $data['deadline']);
		$this->assertSame('Bezwaar parkeerboete', $data['title']);
		$this->assertArrayNotHasKey(CalculationPayloadBuilder::UNRESOLVED_KEY, $data);

		$trace = $this->traces['calculation:case:statutoryTerm'];
		$this->assertSame(RuleVocabulary::VERDICT_ERROR, $trace->getVerdict());
		$this->assertStringContainsString('caseType', (string)$trace->getMessage());
		$this->assertSame(RuleVocabulary::VERDICT_ERROR, $this->traces['calculation:case:deadline']->getVerdict());
		$this->assertSame(RuleVocabulary::VERDICT_FIRED, $this->traces['calculation:case:title']->getVerdict());
	}//end testAnUnresolvedReferenceKeepsTheStoredValueAndTracesIt()
}//end class
