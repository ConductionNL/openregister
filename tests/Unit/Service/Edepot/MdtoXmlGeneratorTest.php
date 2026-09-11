<?php

declare(strict_types=1);

/**
 * MdtoXmlGenerator Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\MdtoEventMapper;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for MdtoXmlGenerator.
 */
class MdtoXmlGeneratorTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private MdtoEventMapper&MockObject $eventMapper;
	private MdtoSourceReader $sourceReader;
	private MdtoXmlGenerator $generator;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->eventMapper = $this->createMock(MdtoEventMapper::class);

		$this->appConfig->method('getValueString')
			->willReturnMap([
				['openregister', 'organisation_identifier', '', 'ORG-001'],
				['openregister', 'organisation_name', '', 'Test Organisation'],
				['openregister', 'organisation_name', 'OpenRegister', 'Test Organisation'],
				['openregister', 'organisation_identifier', 'OpenRegister', 'ORG-001'],
			]);

		$this->eventMapper->method('forObject')->willReturn([]);

		// A REAL source reader, not a mock: which values are found and which
		// are absent is precisely what these tests assert, and a stub would
		// make every absence test pass for the wrong reason.
		$this->sourceReader = new MdtoSourceReader();

		$this->generator = new MdtoXmlGenerator(
			$this->appConfig,
			$this->logger,
			$this->eventMapper,
			$this->sourceReader
		);
	}

	/**
	 * Test generating MDTO XML for a complete object.
	 */
	public function testGenerateCompleteObject(): void {
		$object = $this->createObjectEntity(
			uuid: 'test-uuid-123',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P20Y',
				'classification' => 'A1',
			],
			objectData: ['title' => 'Test Document']
		);

		$xml = $this->generator->generate($object);

		$this->assertStringContainsString('mdto:informatieobject', $xml);
		$this->assertStringContainsString('https://www.nationaalarchief.nl/mdto', $xml);
		$this->assertStringContainsString('test-uuid-123', $xml);
		$this->assertStringContainsString('Test Document', $xml);
		$this->assertStringContainsString('bewaren', $xml);
		$this->assertStringContainsString('P20Y', $xml);
		$this->assertStringContainsString('A1', $xml);
	}

	/**
	 * Test generating MDTO XML with file references.
	 */
	public function testGenerateWithFiles(): void {
		$object = $this->createObjectEntity(
			uuid: 'test-uuid-456',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P10Y',
				'classification' => 'B1',
			]
		);

		$files = [
			[
				'name' => 'document.pdf',
				'size' => 1024,
				'format' => 'application/pdf',
				'checksum' => 'abc123def456',
			],
		];

		$xml = $this->generator->generate($object, $files);

		$this->assertStringContainsString('mdto:bestand', $xml);
		$this->assertStringContainsString('document.pdf', $xml);
		$this->assertStringContainsString('1024', $xml);
		$this->assertStringContainsString('application/pdf', $xml);
		$this->assertStringContainsString('SHA-256', $xml);
		$this->assertStringContainsString('abc123def456', $xml);
	}

	/**
	 * Test that missing optional fields are omitted gracefully.
	 */
	public function testGenerateWithMissingOptionalFields(): void {
		$object = $this->createObjectEntity(
			uuid: 'test-uuid-789',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
			]
		);

		$xml = $this->generator->generate($object);

		// classificatie should default to 'onbekend' when missing.
		$this->assertStringContainsString('onbekend', $xml);
		// toelichting should not appear.
		$this->assertStringNotContainsString('toelichting', $xml);
	}

	/**
	 * Test that missing required fields throw an exception.
	 */
	public function testGenerateMissingRequiredFieldsThrows(): void {
		$object = $this->createObjectEntity(
			uuid: 'test-uuid-000',
			retention: []
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/retention\.archiefnominatie/');

		$this->generator->generate($object);
	}

	/**
	 * Test that missing organisation_identifier throws.
	 */
	public function testGenerateMissingOrganisationThrows(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturn('');

		$generator = new MdtoXmlGenerator($appConfig, $this->logger, $this->eventMapper, $this->sourceReader);

		$object = $this->createObjectEntity(
			uuid: 'test-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
			]
		);

		$this->expectException(InvalidArgumentException::class);

		$generator->generate($object);
	}

	/**
	 * D2: the failure message must not read as an MDTO validity verdict.
	 */
	public function testPreconditionFailureSaysItIsNotAnMdtoValidityCheck(): void {
		$object = $this->createObjectEntity(uuid: 'test-uuid-000', retention: []);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/not an MDTO validity check/');

		$this->generator->generate($object);
	}

	/**
	 * D2: validation widened to the per-file elements MDTO marks minOccurs="1".
	 */
	public function testGenerateRejectsAFileMissingARequiredElement(): void {
		$object = $this->createObjectEntity(
			uuid: 'file-uuid',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y']
		);

		$files = [
			[
				'name' => 'document.pdf',
				'size' => 1024,
				'format' => 'application/pdf',
			],
		];

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/file\[0\]\.checksum/');

		$this->generator->generate($object, $files);
	}

	/**
	 * D2: the gap list names beperkingGebruik when nothing supplies one.
	 */
	public function testGapListNamesBeperkingGebruikWhenUnsourced(): void {
		$object = $this->createObjectEntity(
			uuid: 'gap-uuid',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y']
		);

		$gaps = $this->generator->collectMdtoRequiredElementGaps($object);

		$this->assertNotEmpty($gaps);
		$this->assertStringContainsString('beperkingGebruik', implode(' ', $gaps));
	}

	/**
	 * D2: no gap is reported once a restriction exists.
	 */
	public function testGapListIsEmptyWhenAUseRestrictionExists(): void {
		$object = $this->createObjectEntity(
			uuid: 'gap-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'useRestriction' => 'Geen beperking',
			]
		);

		$this->assertSame([], $this->generator->collectMdtoRequiredElementGaps($object));
	}

	/**
	 * A3: aggregatieniveau from the abstract retention key.
	 */
	public function testAggregationLevelFromRetentionBlock(): void {
		$object = $this->createObjectEntity(
			uuid: 'agg-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'aggregationLevel' => 'Dossier',
			]
		);

		$xml = $this->generator->generate($object);

		$this->assertStringContainsString('<mdto:aggregatieniveau>', $xml);
		$this->assertStringContainsString('<mdto:begripLabel>Dossier</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('<mdto:verwijzingNaam>Aggregatieniveaus</mdto:verwijzingNaam>', $xml);
	}

	/**
	 * A3: aggregatieniveau from the TMLO block, with a begripCode.
	 */
	public function testAggregationLevelFromTmloBlock(): void {
		$object = $this->createObjectEntity(
			uuid: 'agg-uuid',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y'],
			tmlo: ['aggregatieniveau' => ['label' => 'Archiefstuk', 'code' => 'AS']]
		);

		$xml = $this->generator->generate($object);

		$this->assertStringContainsString('<mdto:begripLabel>Archiefstuk</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('<mdto:begripCode>AS</mdto:begripCode>', $xml);
	}

	/**
	 * A3: an unsourced element is ABSENT, not empty and not a placeholder.
	 */
	public function testAggregationLevelAbsentWhenUnsourced(): void {
		$object = $this->createObjectEntity(
			uuid: 'agg-uuid',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y']
		);

		$xml = $this->generator->generate($object);

		$this->assertStringNotContainsString('aggregatieniveau', $xml);
	}

	/**
	 * A3: beperkingGebruik derived from an active legal hold.
	 */
	public function testUseRestrictionFromActiveLegalHold(): void {
		$object = $this->createObjectEntity(
			uuid: 'hold-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'legalHold' => [
					'active' => true,
					'reason' => 'Lopend bezwaar',
					'placedDate' => '2026-03-04T10:11:12+00:00',
				],
			]
		);

		$xml = $this->generator->generate($object);

		$this->assertStringContainsString('<mdto:beperkingGebruik>', $xml);
		$this->assertStringContainsString('<mdto:begripLabel>Overig</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('Legal hold: Lopend bezwaar', $xml);
		$this->assertStringContainsString(
			'<mdto:termijnStartdatumLooptijd>2026-03-04</mdto:termijnStartdatumLooptijd>',
			$xml
		);
	}

	/**
	 * A3: a RELEASED legal hold is not a restriction.
	 */
	public function testUseRestrictionAbsentWhenLegalHoldReleased(): void {
		$object = $this->createObjectEntity(
			uuid: 'hold-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'legalHold' => ['active' => false, 'history' => []],
			]
		);

		$xml = $this->generator->generate($object);

		$this->assertStringNotContainsString('beperkingGebruik', $xml);
	}

	/**
	 * A3: a declared restriction wins over the legal-hold derivation.
	 */
	public function testDeclaredUseRestrictionIsExportedVerbatim(): void {
		$object = $this->createObjectEntity(
			uuid: 'restrict-uuid',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y'],
			tmlo: [
				'beperkingGebruik' => [
					'type' => 'Nader te bepalen',
					'description' => 'Openbaarheid nog te toetsen',
				],
			]
		);

		$xml = $this->generator->generate($object);

		$this->assertStringContainsString('<mdto:begripLabel>Nader te bepalen</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('Openbaarheid nog te toetsen', $xml);
	}

	/**
	 * A3: dekkingInTijd when the declared entry carries a type and a start.
	 */
	public function testTemporalCoverageEmittedWhenTypeAndStartPresent(): void {
		$object = $this->createObjectEntity(
			uuid: 'cov-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'temporalCoverage' => ['type' => 'Looptijd', 'start' => '2021-01-01', 'end' => '2021-12-31'],
			]
		);

		$xml = $this->generator->generate($object);

		$this->assertStringContainsString('<mdto:dekkingInTijd>', $xml);
		$this->assertStringContainsString('<mdto:begripLabel>Looptijd</mdto:begripLabel>', $xml);
		$this->assertStringContainsString(
			'<mdto:dekkingInTijdBegindatum>2021-01-01</mdto:dekkingInTijdBegindatum>',
			$xml
		);
		$this->assertStringContainsString(
			'<mdto:dekkingInTijdEinddatum>2021-12-31</mdto:dekkingInTijdEinddatum>',
			$xml
		);
	}

	/**
	 * A3: a half-declared dekkingInTijd is skipped, not completed.
	 */
	public function testTemporalCoverageAbsentWhenTypeMissing(): void {
		$object = $this->createObjectEntity(
			uuid: 'cov-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'temporalCoverage' => ['start' => '2021-01-01'],
			]
		);

		$xml = $this->generator->generate($object);

		$this->assertStringNotContainsString('dekkingInTijd', $xml);
	}

	/**
	 * D1: events are serialised from the mapper, oldest first, with the actor.
	 */
	public function testEventsAreSerialisedFromTheMapper(): void {
		$eventMapper = $this->createMock(MdtoEventMapper::class);
		$eventMapper->method('forObject')->willReturn(
			[
				[
					'type' => 'Creatie',
					'time' => '2026-01-01T09:00:00+00:00',
					'actorName' => 'Jane Doe',
					'actorId' => 'jdoe',
				],
				['type' => 'Wijziging', 'time' => '2026-02-02T09:00:00+00:00', 'actorName' => null, 'actorId' => null],
			]
		);

		$generator = new MdtoXmlGenerator($this->appConfig, $this->logger, $eventMapper, $this->sourceReader);
		$object = $this->createObjectEntity(
			uuid: 'event-uuid',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y']
		);

		$xml = $generator->generate($object);

		$this->assertSame(2, substr_count($xml, '<mdto:event>'));
		$this->assertStringContainsString('<mdto:begripLabel>Creatie</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('<mdto:begripLabel>Wijziging</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('<mdto:verwijzingNaam>EventTypeLijst</mdto:verwijzingNaam>', $xml);
		$this->assertStringContainsString('<mdto:eventTijd>2026-01-01T09:00:00+00:00</mdto:eventTijd>', $xml);
		$this->assertStringContainsString('<mdto:eventVerantwoordelijkeActor>', $xml);
		$this->assertStringContainsString('<mdto:verwijzingNaam>Jane Doe</mdto:verwijzingNaam>', $xml);
		$this->assertLessThan(
			strpos($xml, '<mdto:begripLabel>Wijziging</mdto:begripLabel>'),
			strpos($xml, '<mdto:begripLabel>Creatie</mdto:begripLabel>')
		);
		// eventResultaat is deliberately never emitted.
		$this->assertStringNotContainsString('eventResultaat', $xml);
	}

	/**
	 * D1: no qualifying audit rows means no event element at all.
	 */
	public function testEventsAbsentWhenTheMapperReturnsNothing(): void {
		$object = $this->createObjectEntity(
			uuid: 'event-uuid',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y']
		);

		$xml = $this->generator->generate($object);

		$this->assertStringNotContainsString('<mdto:event>', $xml);
	}

	/**
	 * The new elements sit in the XSD's informatieobjectType sequence order.
	 */
	public function testElementOrderFollowsTheXsdSequence(): void {
		$eventMapper = $this->createMock(MdtoEventMapper::class);
		$eventMapper->method('forObject')->willReturn(
			[['type' => 'Creatie', 'time' => '2026-01-01T09:00:00+00:00', 'actorName' => null, 'actorId' => null]]
		);

		$generator = new MdtoXmlGenerator($this->appConfig, $this->logger, $eventMapper, $this->sourceReader);
		$object = $this->createObjectEntity(
			uuid: 'order-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'aggregationLevel' => 'Dossier',
				'temporalCoverage' => ['type' => 'Looptijd', 'start' => '2021-01-01'],
				'useRestriction' => 'Geen beperking',
			]
		);

		$xml = $generator->generate($object);

		$positions = [];
		foreach (
			[
				'<mdto:naam>',
				'<mdto:aggregatieniveau>',
				'<mdto:dekkingInTijd>',
				'<mdto:event>',
				'<mdto:waardering>',
				'<mdto:bewaartermijn>',
				'<mdto:informatiecategorie>',
				'<mdto:archiefvormer>',
				'<mdto:beperkingGebruik>',
			] as $tag
		) {
			$at = strpos($xml, $tag);
			$this->assertNotFalse($at, $tag . ' is missing from the document');
			$positions[] = $at;
		}

		$sorted = $positions;
		sort($sorted);
		$this->assertSame($sorted, $positions, 'Elements are not in the MDTO XSD sequence order');
	}

	/**
	 * Create a mock ObjectEntity with the given data.
	 *
	 * @param string $uuid The UUID.
	 * @param array<string,mixed> $retention The retention data.
	 * @param array<string,mixed> $objectData The object data.
	 * @param array<string,mixed> $tmlo The TMLO data.
	 *
	 * @return ObjectEntity&MockObject The mock object entity.
	 */
	private function createObjectEntity(
		string $uuid = 'test-uuid',
		array $retention = [],
		array $objectData = [],
		array $tmlo = [],
	): ObjectEntity&MockObject {
		$object = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->onlyMethods(['getUuid'])
			->addMethods(['getRetention', 'getTmlo'])
			->getMock();
		$object->method('getUuid')->willReturn($uuid);
		$object->method('getRetention')->willReturn($retention);
		$object->method('getTmlo')->willReturn($tmlo);
		$object->method('getObject')->willReturn($objectData);

		return $object;
	}
}
