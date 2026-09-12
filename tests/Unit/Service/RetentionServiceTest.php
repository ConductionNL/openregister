<?php

declare(strict_types=1);

/**
 * RetentionService Unit Tests
 *
 * Tests archival metadata application, archiefactiedatum calculation,
 * selectielijst lookup, legal hold management, and immutability validation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for RetentionService
 */
class RetentionServiceTest extends TestCase {

	private MagicMapper&MockObject $objectMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private RegisterMapper&MockObject $registerMapper;
	private AuditTrailMapper&MockObject $auditMapper;
	private ObjectRetentionHandler&MockObject $settingsHandler;
	private IAppConfig&MockObject $appConfig;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private RetentionRowScanner&MockObject $rowScanner;
	private RetentionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->auditMapper = $this->createMock(AuditTrailMapper::class);
		$this->settingsHandler = $this->createMock(ObjectRetentionHandler::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rowScanner = $this->createMock(RetentionRowScanner::class);

		$this->service = new RetentionService(
			$this->objectMapper,
			$this->schemaMapper,
			$this->registerMapper,
			$this->auditMapper,
			$this->settingsHandler,
			$this->appConfig,
			$this->userSession,
			$this->logger,
			$this->rowScanner,
			// The REAL resolver over the SAME mapper mock the tests program, not
			// a mock of its own: the derivation tests below assert what the
			// resolver actually does with a relation, and a mocked resolver
			// would assert only that this class called it.
			new ArchiveActionDateCalculator($this->objectMapper, $this->logger),
		);
	}//end setUp()

	/**
	 * Test that archival metadata is applied when schema has archive enabled.
	 */
	public function testApplyArchivalMetadataWithEnabledSchema(): void {
		$object = new ObjectEntity();
		$object->setRetention([]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'defaultNominatie' => 'vernietigen',
			'defaultBewaartermijn' => 'P5Y',
		]);

		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn([
			'selectielijstRegister' => null,
			'selectielijstSchema' => null,
		]);

		$result = $this->service->applyArchivalMetadata($object, $schema);
		$retention = $result->getRetention();

		$this->assertEquals('vernietigen', $retention['archiefnominatie']);
		// GAP A4: the Archiefwet lifecycle in English, one vocabulary shared
		// with TmloService and the abstract `_retention` layer.
		$this->assertEquals('active', $retention['archiefstatus']);
		$this->assertEquals('P5Y', $retention['bewaartermijn']);
		$this->assertNotNull($retention['archiefactiedatum']);
	}//end testApplyArchivalMetadataWithEnabledSchema()

	/**
	 * GAP B1: the selectielijst VERSION is recorded, not only its name.
	 *
	 * The same category carries different retention periods across
	 * selectielijst revisions, so a decision justified by "Selectielijst
	 * gemeenten 2020" alone cannot be defended once that list moves. The row's
	 * own `versie` is what an auditor outside this install can check.
	 */
	public function testApplyArchivalMetadataRecordsTheSelectielijstVersion(): void {
		$object = new ObjectEntity();
		$object->setRetention([]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'classification' => '4.1.2',
		]);

		$this->stubSelectielijstEntry([
			'categorie' => '4.1.2',
			'archiefnominatie' => 'vernietigen',
			'bewaartermijn' => 'P10Y',
			'bron' => 'Selectielijst gemeenten 2020',
			'versie' => '2020.2',
		]);

		$retention = $this->service->applyArchivalMetadata($object, $schema)->getRetention();

		$this->assertSame('Selectielijst gemeenten 2020', $retention['selectielijstBron']);
		$this->assertSame('2020.2', $retention['selectionListVersion']);
		$this->assertNotEmpty($retention['selectionListConsultedAt']);
	}//end testApplyArchivalMetadataRecordsTheSelectielijstVersion()

	/**
	 * A row that numbers nothing still says WHICH STORED REVISION was read.
	 *
	 * `@self.version` is not the publisher's numbering and does not travel, but
	 * it separates "the row as it stood then" from "the row as it stands now",
	 * which is more than the name alone could ever say.
	 */
	public function testApplyArchivalMetadataFallsBackToTheStoredRowVersion(): void {
		$object = new ObjectEntity();
		$object->setRetention([]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'classification' => '4.1.2',
		]);

		$this->stubSelectielijstEntry(
			[
				'categorie' => '4.1.2',
				'archiefnominatie' => 'vernietigen',
				'bewaartermijn' => 'P10Y',
				'bron' => 'Selectielijst gemeenten 2020',
			],
			version: '0.0.3'
		);

		$retention = $this->service->applyArchivalMetadata($object, $schema)->getRetention();

		$this->assertSame('0.0.3', $retention['selectionListVersion']);
	}//end testApplyArchivalMetadataFallsBackToTheStoredRowVersion()

	/**
	 * No selectielijst, no provenance keys. An absent key is silence; a key
	 * holding null is a recorded answer of "no version", which is a different
	 * and false claim.
	 */
	public function testApplyArchivalMetadataOmitsProvenanceWithoutASelectielijst(): void {
		$object = new ObjectEntity();
		$object->setRetention([]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'defaultNominatie' => 'vernietigen',
			'defaultBewaartermijn' => 'P5Y',
		]);

		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn([
			'selectielijstRegister' => null,
			'selectielijstSchema' => null,
		]);

		$retention = $this->service->applyArchivalMetadata($object, $schema)->getRetention();

		$this->assertArrayNotHasKey('selectionListVersion', $retention);
		$this->assertArrayNotHasKey('selectionListConsultedAt', $retention);
	}//end testApplyArchivalMetadataOmitsProvenanceWithoutASelectielijst()

	/**
	 * Stub a configured selectielijst that answers with one entry.
	 *
	 * @param array       $data    The entry's stored object data
	 * @param string|null $version The entry's own `@self.version`, if any
	 */
	private function stubSelectielijstEntry(array $data, ?string $version = null): void {
		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn([
			'selectielijstRegister' => 1,
			'selectielijstSchema' => 2,
		]);

		$entry = new ObjectEntity();
		$entry->setObject($data);
		if ($version !== null) {
			$entry->setVersion($version);
		}

		$this->objectMapper->method('findAll')->willReturn([$entry]);
	}//end stubSelectielijstEntry()

	/**
	 * GAP C1: a relation-based derivation follows the reference and reads the
	 * date off the record it points at.
	 *
	 * ZGW names five methods after zaak and besluit concepts, but the mechanic
	 * is one: the schema says which property holds the reference and which
	 * property on the target holds the date. openregister never learns what a
	 * besluit is, which is what keeps it schema-agnostic.
	 */
	public function testDatesFromARelatedRecord(): void {
		$object = new ObjectEntity();
		$object->setObject(['besluit' => 'related-uuid']);

		$related = new ObjectEntity();
		$related->setObject(['ingangsdatum' => '2020-03-01']);
		$this->objectMapper->method('find')->willReturn($related);

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'afleidingswijze' => 'ingangsdatum_besluit',
			'sourceRelation' => 'besluit',
			'sourceRelationProperty' => 'ingangsdatum',
		]);

		$date = $this->service->calculateArchiveActionDate($object, $schema, 'P5Y');

		$this->assertSame('2025-03-01', $date);
	}//end testDatesFromARelatedRecord()

	/**
	 * A relation holding a LIST resolves through its first entry.
	 *
	 * A disposal date derived from several unrelated dates is not derived at
	 * all, so the choice is deliberate and logged rather than silent.
	 */
	public function testDatesFromTheFirstOfSeveralRelations(): void {
		$object = new ObjectEntity();
		$object->setObject(['zaken' => ['first-uuid', 'second-uuid']]);

		$related = new ObjectEntity();
		$related->setObject(['startdatum' => '2021-06-15']);
		$this->objectMapper->expects($this->once())
			->method('find')
			->with('first-uuid')
			->willReturn($related);

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'afleidingswijze' => 'gerelateerde_zaak',
			'sourceRelation' => 'zaken',
			'sourceRelationProperty' => 'startdatum',
		]);

		$this->assertSame('2026-06-15', $this->service->calculateArchiveActionDate($object, $schema, 'P5Y'));
	}//end testDatesFromTheFirstOfSeveralRelations()

	/**
	 * GAP C1: an unresolvable relation produces NO date, not a date from
	 * creation.
	 *
	 * This is the whole point of the gap. Dating from creation when the schema
	 * asked for the related decision's date is a plausible wrong answer, and a
	 * wrong disposal date in that direction keeps personal data past its term.
	 * No date is a visible gap a records officer can act on.
	 */
	public function testRefusesADateWhenTheRelationCannotBeResolved(): void {
		$object = new ObjectEntity();
		$object->setObject(['besluit' => 'missing-uuid']);
		$object->setCreated(new \DateTime('2019-01-01'));

		$this->objectMapper->method('find')->willThrowException(new \Exception('not found'));

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'afleidingswijze' => 'ingangsdatum_besluit',
			'sourceRelation' => 'besluit',
			'sourceRelationProperty' => 'ingangsdatum',
		]);

		$this->assertNull($this->service->calculateArchiveActionDate($object, $schema, 'P5Y'));
	}//end testRefusesADateWhenTheRelationCannotBeResolved()

	/**
	 * A relation-based method with no relation configured refuses too.
	 *
	 * A validation list that validates nothing is the defect this whole gap is
	 * about, so the missing configuration has to be as loud as the missing
	 * record.
	 */
	public function testRefusesADateWhenTheRelationIsNotConfigured(): void {
		$object = new ObjectEntity();
		$object->setObject([]);
		$object->setCreated(new \DateTime('2019-01-01'));

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'afleidingswijze' => 'hoofdzaak',
		]);

		$this->assertNull($this->service->calculateArchiveActionDate($object, $schema, 'P5Y'));
	}//end testRefusesADateWhenTheRelationIsNotConfigured()

	/**
	 * GAP C1: `ander_datumkenmerk` reads a named date property on this record.
	 *
	 * The one method of the six that needs no relation: ZGW's catch-all for a
	 * date attribute that is not a zaak-eigenschap.
	 */
	public function testDatesFromANamedDatePropertyOnTheRecord(): void {
		$object = new ObjectEntity();
		$object->setObject(['vervaldatum' => '2022-12-31']);

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'afleidingswijze' => 'ander_datumkenmerk',
			'sourceDateProperty' => 'vervaldatum',
		]);

		$this->assertSame('2027-12-31', $this->service->calculateArchiveActionDate($object, $schema, 'P5Y'));
	}//end testDatesFromANamedDatePropertyOnTheRecord()

	/**
	 * `afgehandeld` keeps its creation-date fallback.
	 *
	 * A record with no recorded closure was created and has been open since,
	 * which is a defensible source. The six new methods are the ones where it
	 * is not, so only they refuse. This test is what stops the refusal being
	 * widened by accident.
	 */
	public function testAfgehandeldStillFallsBackToCreation(): void {
		$object = new ObjectEntity();
		$object->setObject([]);
		$object->setCreated(new \DateTime('2019-01-01'));

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'afleidingswijze' => 'afgehandeld',
		]);

		$this->assertSame('2024-01-01', $this->service->calculateArchiveActionDate($object, $schema, 'P5Y'));
	}//end testAfgehandeldStillFallsBackToCreation()

	/**
	 * A method ZGW does not define is still refused outright.
	 *
	 * Gap C2 wired VALID_AFLEIDINGSWIJZEN up; widening it to nine must not
	 * have quietly turned it back into a list that validates nothing.
	 */
	public function testStillRefusesAMethodZgwDoesNotDefine(): void {
		$object = new ObjectEntity();
		$object->setObject([]);
		$object->setCreated(new \DateTime('2019-01-01'));

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([
			'enabled' => true,
			'afleidingswijze' => 'zomaar_iets',
		]);

		$this->assertNull($this->service->calculateArchiveActionDate($object, $schema, 'P5Y'));
	}//end testStillRefusesAMethodZgwDoesNotDefine()

	/**
	 * The pending-destruction-list exclusion filters on a REAL schema property.
	 *
	 * MagicSearchHandler compares a filter key against the schema's own
	 * property names and turns anything it does not recognise into `1 = 0`
	 * rather than raising. The key was `object->status`, which is not a
	 * property name, so this query returned nothing on every run and the
	 * exclusion it feeds was a no-op: every sweep re-listed objects that were
	 * already awaiting approval.
	 *
	 * The assertion is on the filter KEY rather than on the result, because
	 * the result was empty both before and after: a test that only checked the
	 * return value could not tell the bug from the fix.
	 */
	public function testExcludesObjectsAlreadyOnAPendingList(): void {
		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn([
			'destructionListRegister' => 1,
			'destructionListSchema' => 2,
		]);

		$list = new ObjectEntity();
		$list->setObject(['objects' => [['uuid' => 'already-listed']]]);

		$this->objectMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->anything(),
				$this->anything(),
				$this->callback(function (array $filters): bool {
					return array_key_exists('status', $filters)
						&& array_key_exists('object->status', $filters) === false;
				})
			)
			->willReturn([$list]);

		$this->assertSame(['already-listed'], $this->service->getObjectsOnPendingDestructionLists());
	}//end testExcludesObjectsAlreadyOnAPendingList()

	/**
	 * GAP A4: the immutability guard still recognises the Dutch spellings.
	 *
	 * This is the half of the vocabulary change that could destroy something.
	 * Stored data carries whatever spelling was current when it was written and
	 * there is no migration, so a guard that only knew `transferred` would
	 * unlock every record an existing install had already handed to an e-Depot.
	 *
	 * @dataProvider immutableSpellings
	 *
	 * @param string $stored   The spelling held in storage
	 * @param string $expected The error code it must still produce
	 */
	public function testTheImmutabilityGuardKnowsBothVocabularies(string $stored, string $expected): void {
		$object = new ObjectEntity();
		$object->setRetention(['archiefstatus' => $stored]);

		$this->assertSame($expected, $this->service->validateNotImmutable($object));
	}//end testTheImmutabilityGuardKnowsBothVocabularies()

	/**
	 * Every spelling of every immutable state, and what it means.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function immutableSpellings(): array {
		return [
			'english transferred' => ['transferred', 'OBJECT_TRANSFERRED'],
			'dutch transferred' => ['overgebracht', 'OBJECT_TRANSFERRED'],
			'english destroyed' => ['destroyed', 'OBJECT_DESTROYED'],
			'dutch destroyed' => ['vernietigd', 'OBJECT_DESTROYED'],
		];
	}//end immutableSpellings()

	/**
	 * A live record is not immutable, in either vocabulary.
	 *
	 * The mirror of the test above: widening the guard until it matched
	 * everything would pass that one and freeze every record in the install.
	 *
	 * @dataProvider liveSpellings
	 *
	 * @param string $stored The spelling held in storage
	 */
	public function testALiveRecordIsNotImmutableInEitherVocabulary(string $stored): void {
		$object = new ObjectEntity();
		$object->setRetention(['archiefstatus' => $stored]);

		$this->assertNull($this->service->validateNotImmutable($object));
	}//end testALiveRecordIsNotImmutableInEitherVocabulary()

	/**
	 * Every spelling that means the record is still live.
	 *
	 * @return array<string, array{string}>
	 */
	public static function liveSpellings(): array {
		return [
			'english active' => ['active'],
			'dutch actief' => ['actief'],
			'dutch nog_te_archiveren' => ['nog_te_archiveren'],
			'english semi static' => ['semi_static'],
			'dutch semi statisch' => ['semi_statisch'],
		];
	}//end liveSpellings()

	/**
	 * Test that archival metadata is NOT applied when schema has no archive config.
	 */
	public function testApplyArchivalMetadataSkipsWhenDisabled(): void {
		$object = new ObjectEntity();
		$object->setRetention([]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn([]);

		$result = $this->service->applyArchivalMetadata($object, $schema);
		$retention = $result->getRetention();

		$this->assertArrayNotHasKey('archiefnominatie', $retention);
	}//end testApplyArchivalMetadataSkipsWhenDisabled()

	/**
	 * Test that destroyed objects are flagged as immutable.
	 */
	public function testValidateNotImmutableReturnsDestroyedCode(): void {
		$object = new ObjectEntity();
		$object->setRetention(['archiefstatus' => 'vernietigd']);

		$result = $this->service->validateNotImmutable($object);

		$this->assertEquals('OBJECT_DESTROYED', $result);
	}//end testValidateNotImmutableReturnsDestroyedCode()

	/**
	 * Test that transferred objects are flagged as immutable.
	 */
	public function testValidateNotImmutableReturnsTransferredCode(): void {
		$object = new ObjectEntity();
		$object->setRetention(['archiefstatus' => 'overgebracht']);

		$result = $this->service->validateNotImmutable($object);

		$this->assertEquals('OBJECT_TRANSFERRED', $result);
	}//end testValidateNotImmutableReturnsTransferredCode()

	/**
	 * Test that mutable objects return null.
	 */
	public function testValidateNotImmutableReturnsNullForMutable(): void {
		$object = new ObjectEntity();
		$object->setRetention(['archiefstatus' => 'nog_te_archiveren']);

		$result = $this->service->validateNotImmutable($object);

		$this->assertNull($result);
	}//end testValidateNotImmutableReturnsNullForMutable()

	/**
	 * Test placing a legal hold.
	 */
	public function testPlaceLegalHold(): void {
		$object = new ObjectEntity();
		$object->setRetention([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$this->userSession->method('getUser')->willReturn($user);

		$result = $this->service->placeLegalHold($object, 'WOO-verzoek 2025-0142');
		$retention = $result->getRetention();

		$this->assertTrue($retention['legalHold']['active']);
		$this->assertEquals('WOO-verzoek 2025-0142', $retention['legalHold']['reason']);
		$this->assertEquals('test-user', $retention['legalHold']['placedBy']);
	}//end testPlaceLegalHold()

	/**
	 * Test releasing a legal hold preserves history.
	 */
	public function testReleaseLegalHold(): void {
		$object = new ObjectEntity();
		$object->setRetention([
			'legalHold' => [
				'active' => true,
				'reason' => 'WOO-verzoek',
				'placedBy' => 'admin',
				'placedDate' => '2026-01-01T00:00:00+00:00',
				'history' => [],
			],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$this->userSession->method('getUser')->willReturn($user);

		$result = $this->service->releaseLegalHold($object, 'WOO afgehandeld');
		$retention = $result->getRetention();

		$this->assertFalse($retention['legalHold']['active']);
		$this->assertCount(1, $retention['legalHold']['history']);
		$this->assertEquals('WOO afgehandeld', $retention['legalHold']['history'][0]['releaseReason']);
	}//end testReleaseLegalHold()

	/**
	 * Test hasActiveLegalHold returns true when hold is active.
	 */
	public function testHasActiveLegalHoldTrue(): void {
		$object = new ObjectEntity();
		$object->setRetention([
			'legalHold' => ['active' => true],
		]);

		$this->assertTrue($this->service->hasActiveLegalHold($object));
	}//end testHasActiveLegalHoldTrue()

	/**
	 * Test hasActiveLegalHold returns false when no hold.
	 */
	public function testHasActiveLegalHoldFalseWhenNoHold(): void {
		$object = new ObjectEntity();
		$object->setRetention([]);

		$this->assertFalse($this->service->hasActiveLegalHold($object));
	}//end testHasActiveLegalHoldFalseWhenNoHold()

	/**
	 * Test extending archiefactiedatum by default period.
	 */
	public function testExtendArchiefactiedatum(): void {
		$object = new ObjectEntity();
		$object->setRetention([
			'archiefactiedatum' => '2026-01-01',
		]);

		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn([
			'defaultExtensionPeriod' => 'P1Y',
		]);

		$result = $this->service->extendArchiveActionDate($object);
		$retention = $result->getRetention();

		$this->assertEquals('2027-01-01', $retention['archiefactiedatum']);
	}//end testExtendArchiefactiedatum()

	/**
	 * Test destruction certificate generation.
	 */
	public function testGenerateDestructionCertificate(): void {
		$listData = [
			'objects' => [
				['schema' => '1', 'classification' => 'B1'],
				['schema' => '1', 'classification' => 'B1'],
				['schema' => '2', 'classification' => 'A1'],
			],
			'approvals' => [
				['userId' => 'archivist-1'],
			],
		];

		$result = $this->service->generateDestructionCertificate($listData, 3, '2026-03-22T10:00:00+00:00');

		$this->assertEquals('verklaring_van_vernietiging', $result['type']);
		$this->assertEquals(3, $result['totalDestroyed']);
		$this->assertCount(2, $result['groupedBySchema']);
		$this->assertTrue($result['immutable']);
		$this->assertEquals(['archivist-1'], $result['approvedBy']);
	}//end testGenerateDestructionCertificate()
}//end class
