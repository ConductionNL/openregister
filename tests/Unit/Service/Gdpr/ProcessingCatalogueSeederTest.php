<?php

declare(strict_types=1);

/**
 * ProcessingCatalogueSeeder unit tests.
 *
 * A register import writes the processing activities an app declares into the
 * record of processing, as concepts, and never touches one already there.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-apps-must-contribute-processing-activities-and-attribution-declaratively-via-x-openregister-processing-or-pa-2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Service\Gdpr\ProcessingCatalogueSeeder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ProcessingCatalogueSeeder.
 */
class ProcessingCatalogueSeederTest extends TestCase {

	/**
	 * The record of processing.
	 *
	 * @var VerwerkingsactiviteitMapperInterface&MockObject
	 */
	private VerwerkingsactiviteitMapperInterface $activities;

	/**
	 * Activities written during the test, by code.
	 *
	 * @var array<string, Verwerkingsactiviteit>
	 */
	private array $written = [];

	/**
	 * The seeder under test.
	 *
	 * @var ProcessingCatalogueSeeder
	 */
	private ProcessingCatalogueSeeder $seeder;

	/**
	 * Set up a record of processing that holds what was inserted and refuses what the mapper refuses.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->written = [];
		$this->activities = $this->createMock(VerwerkingsactiviteitMapperInterface::class);
		$this->activities->method('findByCode')->willReturnCallback(fn (string $code): ?Verwerkingsactiviteit => $this->written[$code] ?? null);
		$this->activities->method('insert')->willReturnCallback(function (Verwerkingsactiviteit $activity): Verwerkingsactiviteit {
			if ($activity->getPurpose() === null) {
				throw new InvalidArgumentException('Verwerkingsactiviteit MUST have a purpose (AVG Art 30 §1(b))');
			}

			$this->written[(string)$activity->getCode()] = $activity;
			return $activity;
		});
		$this->seeder = new ProcessingCatalogueSeeder(activities: $this->activities, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * A schema block that is itself one activity (learniq's shape) is written as a concept.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-register-import-seeds-the-apps-catalogue-as-drafts
	 */
	public function testASchemaBlockWithACodeIsSeededAsAConcept(): void {
		$result = $this->seeder->seed(document: $this->document(schemas: [
			'Cohort' => ['x-openregister-processing' => [
				'code' => 'teaching',
				'naam' => 'Teaching and enrolment',
				'doelbinding' => 'Deliver education.',
				'rechtsgrond' => 'public-task',
				'dataCategories' => ['teacherIds', 'learnerIds'],
				'retentionReference' => 'not declared (Cohort carries no x-openregister-archival annotation; align with the selectielijst)',
				'attribution' => ['default' => 'teaching'],
			]],
		]));

		self::assertSame(['created' => ['teaching'], 'kept' => [], 'refused' => []], $result);
		$activity = $this->written['teaching'];
		self::assertSame('Teaching and enrolment', $activity->getName());
		self::assertSame('Deliver education.', $activity->getPurpose());
		self::assertSame('public_task', $activity->getLegalBasis());
		self::assertSame(['teacherIds', 'learnerIds'], $activity->getPersonalDataCategories());
		self::assertSame('concept', $activity->getStatus());
		// A prose retention note is not a period; the column would refuse it.
		self::assertNull($activity->getRetentionPeriod());
	}//end testASchemaBlockWithACodeIsSeededAsAConcept()

	/**
	 * The `activities` catalogue on a register is read, keyed by code, with English field names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-register-import-seeds-the-apps-catalogue-as-drafts
	 */
	public function testARegisterCatalogueIsSeededByCode(): void {
		$result = $this->seeder->seed(document: [
			'components' => ['registers' => ['permits' => ['configuration' => ['x-openregister-processing' => [
				'activities' => [
					'permit-handling' => [
						'name' => 'Behandelen omgevingsvergunning',
						'purpose' => 'Decide on permit applications.',
						'legalBasis' => 'publieke-taak',
						'retentionPeriod' => 'P20Y',
					],
				],
			]]]]],
		]);

		self::assertSame(['permit-handling'], $result['created']);
		self::assertSame('public_task', $this->written['permit-handling']->getLegalBasis());
		self::assertSame('P20Y', $this->written['permit-handling']->getRetentionPeriod());
	}//end testARegisterCatalogueIsSeededByCode()

	/**
	 * A code already in the record is left alone and nothing is written for it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-re-import-never-overwrites-privacy-officer-edits
	 */
	public function testAnExistingCodeIsKeptUntouched(): void {
		$edited = new Verwerkingsactiviteit();
		$edited->setCode('teaching');
		$edited->setPurpose('Amended by the privacy officer.');
		$edited->setStatus('published');
		$this->written['teaching'] = $edited;

		$result = $this->seeder->seed(document: $this->document(schemas: [
			'Cohort' => ['x-openregister-processing' => ['code' => 'teaching', 'naam' => 'Teaching', 'doelbinding' => 'Original text.', 'rechtsgrond' => 'public-task']],
		]));

		self::assertSame(['created' => [], 'kept' => ['teaching'], 'refused' => []], $result);
		self::assertSame('Amended by the privacy officer.', $this->written['teaching']->getPurpose());
		self::assertSame('published', $this->written['teaching']->getStatus());
	}//end testAnExistingCodeIsKeptUntouched()

	/**
	 * Schemas sharing one activity write it once; the first declaration wins.
	 *
	 * @return void
	 */
	public function testASharedCodeIsWrittenOnce(): void {
		$block = ['code' => 'care', 'naam' => 'Care', 'doelbinding' => 'Support learners.', 'rechtsgrond' => 'public-task'];
		$result = $this->seeder->seed(document: $this->document(schemas: [
			'SupportRequest' => ['x-openregister-processing' => $block],
			'DossierNote' => ['x-openregister-processing' => array_merge($block, ['naam' => 'Other name'])],
		]));

		self::assertSame(['care'], $result['created']);
		self::assertSame('Care', $this->written['care']->getName());
	}//end testASharedCodeIsWrittenOnce()

	/**
	 * An entry the record refuses is named with the reason, and the next entry still lands.
	 *
	 * @return void
	 */
	public function testARefusedEntryIsNamedAndTheRestLands(): void {
		$result = $this->seeder->seed(document: $this->document(schemas: [
			'Broken' => ['x-openregister-processing' => ['code' => 'broken', 'naam' => 'No purpose', 'rechtsgrond' => 'consent']],
			'Fine' => ['x-openregister-processing' => ['code' => 'fine', 'naam' => 'Fine', 'doelbinding' => 'A purpose.', 'rechtsgrond' => 'toestemming']],
		]));

		self::assertSame(['fine'], $result['created']);
		self::assertSame(['broken' => 'Verwerkingsactiviteit MUST have a purpose (AVG Art 30 §1(b))'], $result['refused']);
		self::assertSame('consent', $this->written['fine']->getLegalBasis());
	}//end testARefusedEntryIsNamedAndTheRestLands()

	/**
	 * A block with only an attribution (no code of its own) declares no activity.
	 *
	 * @return void
	 */
	public function testAnAttributionOnlyBlockDeclaresNothing(): void {
		$entries = $this->seeder->declaredEntries(document: $this->document(schemas: [
			'Lesson' => ['x-openregister-processing' => ['attribution' => ['default' => 'teaching'], 'logReads' => false]],
			'Plain' => ['title' => 'No block'],
		]));

		self::assertSame([], $entries);
	}//end testAnAttributionOnlyBlockDeclaresNothing()

	/**
	 * A configuration document with these schema definitions.
	 *
	 * @param array<string, array<string, mixed>> $schemas The schemas.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function document(array $schemas): array {
		return ['components' => ['schemas' => $schemas]];
	}//end document()
}//end class
