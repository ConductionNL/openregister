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
use OCA\OpenRegister\Service\Edepot\MdtoBestandGenerator;
use OCA\OpenRegister\Service\Edepot\MdtoDocumentWriter;
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

		$this->generator = $this->makeGenerator(appConfig: $this->appConfig, eventMapper: $this->eventMapper);
	}

	/**
	 * Build a generator with real collaborators except the ones a test varies.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param MdtoEventMapper $eventMapper The event mapper.
	 *
	 * @return MdtoXmlGenerator The generator.
	 */
	private function makeGenerator(IAppConfig $appConfig, MdtoEventMapper $eventMapper): MdtoXmlGenerator {
		$writer = new MdtoDocumentWriter();

		return new MdtoXmlGenerator(
			$appConfig,
			$this->logger,
			$eventMapper,
			$this->sourceReader,
			$writer,
			new MdtoBestandGenerator($writer)
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
	 * Deviation 2: a file is referenced from the informatieobject, never nested in it.
	 */
	public function testFilesAreReferencedNotNested(): void {
		$object = $this->createObjectEntity(
			uuid: 'test-uuid-456',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P10Y', 'classification' => 'B1']
		);

		$xml = $this->generator->generate($object, [$this->file()]);

		$this->assertStringNotContainsString('<mdto:bestand>', $xml);
		$this->assertStringContainsString('<mdto:heeftRepresentatie>', $xml);
		$this->assertStringContainsString('<mdto:verwijzingNaam>document.pdf</mdto:verwijzingNaam>', $xml);
		$this->assertStringContainsString('<mdto:identificatieKenmerk>' . str_repeat('ab', 32) . '</mdto:identificatieKenmerk>', $xml);
	}

	/**
	 * Deviation 2: each file gets its own bestand document, pointing back at its object.
	 */
	public function testGenerateBestandDescribesTheFileAndItsObject(): void {
		$object = $this->createObjectEntity(
			uuid: 'test-uuid-456',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P10Y'],
			objectData: ['title' => 'Besluit 14']
		);

		$xml = $this->generator->generateBestand($object, $this->file());

		$this->assertStringContainsString('<mdto:MDTO', $xml);
		$this->assertStringContainsString('<mdto:bestand>', $xml);
		$this->assertStringNotContainsString('<mdto:informatieobject>', $xml);
		$this->assertStringContainsString('<mdto:omvang>1024</mdto:omvang>', $xml);
		// bestandsformaat as the standard's own example shows a media type.
		$this->assertStringContainsString('<mdto:begripLabel>pdf</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('<mdto:begripCode>application/pdf</mdto:begripCode>', $xml);
		$this->assertStringContainsString('<mdto:verwijzingNaam>IANA Media types</mdto:verwijzingNaam>', $xml);
		// Deviation 5: checksumDatum is present.
		$this->assertStringContainsString('<mdto:checksumDatum>2026-09-11T10:15:00+00:00</mdto:checksumDatum>', $xml);
		$this->assertStringContainsString('<mdto:verwijzingNaam>Begrippenlijst ChecksumAlgoritme MDTO</mdto:verwijzingNaam>', $xml);
		// isRepresentatieVan names the object by the identificatie its own document carries.
		$this->assertStringContainsString('<mdto:isRepresentatieVan>', $xml);
		$this->assertStringContainsString('<mdto:verwijzingNaam>Besluit 14</mdto:verwijzingNaam>', $xml);
		$this->assertStringContainsString('<mdto:identificatieKenmerk>test-uuid-456</mdto:identificatieKenmerk>', $xml);
	}

	/**
	 * A file whose checksum is not a SHA-256 is refused, because the document declares SHA-256.
	 */
	public function testGenerateBestandRefusesAChecksumThatIsNotSha256(): void {
		$object = $this->createObjectEntity(uuid: 'u', retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P1Y']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/file\[0\]\.checksum \(a SHA-256\)/');

		$this->generator->generateBestand($object, ['checksum' => 'abc123def456'] + $this->file());
	}

	/**
	 * Deviation 5: a file without a checksum date is refused rather than stamped.
	 */
	public function testGenerateRefusesAFileWithoutAChecksumDate(): void {
		$object = $this->createObjectEntity(uuid: 'u', retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P1Y']);
		$file = $this->file();
		unset($file['checksumDate']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/file\[0\]\.checksumDate/');

		$this->generator->generate($object, [$file]);
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

		// Deviation 3: no category means no informatiecategorie, not the old 'onbekend' placeholder.
		$this->assertStringNotContainsString('informatiecategorie', $xml);
		$this->assertStringNotContainsString('onbekend', $xml);
		// No toelichting means no omschrijving.
		$this->assertStringNotContainsString('omschrijving', $xml);
		$this->assertStringNotContainsString('toelichting', $xml);
	}

	/**
	 * Deviation 1: the root is MDTO, carrying the schema version, with one informatieobject in it.
	 */
	public function testTheRootIsMdtoWithTheSchemaLocation(): void {
		$object = $this->createObjectEntity(uuid: 'root-uuid', retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y']);

		$dom = new \DOMDocument();
		$dom->loadXML($this->generator->generate($object));

		$this->assertSame('MDTO', $dom->documentElement->localName);
		$this->assertSame(MdtoDocumentWriter::MDTO_NAMESPACE, $dom->documentElement->namespaceURI);
		$this->assertStringContainsString(
			MdtoDocumentWriter::SCHEMA_LOCATION,
			$dom->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation')
		);
		$this->assertSame(1, $dom->documentElement->childNodes->length - $this->whitespaceNodes($dom->documentElement));
		$this->assertSame('informatieobject', $dom->documentElement->firstElementChild->localName);
	}

	/**
	 * Deviation 6: toelichting is exported as MDTO's omschrijving.
	 */
	public function testToelichtingBecomesOmschrijving(): void {
		$object = $this->createObjectEntity(
			uuid: 'desc-uuid',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y', 'toelichting' => 'Bij besluit 14']
		);

		$xml = $this->generator->generate($object);

		$this->assertStringContainsString('<mdto:omschrijving>Bij besluit 14</mdto:omschrijving>', $xml);
		$this->assertStringNotContainsString('toelichting', $xml);
	}

	/**
	 * Deviation 3: waardering is a begripGegevens from the CLOSED Waarderingen list.
	 *
	 * @param string $nominatie The stored archiefnominatie.
	 * @param string $code The expected Waarderingen code.
	 * @param string $label The expected Waarderingen label.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('appraisals')]
	public function testWaarderingUsesTheClosedWaarderingenList(string $nominatie, string $code, string $label): void {
		$object = $this->createObjectEntity(uuid: 'w', retention: ['archiefnominatie' => $nominatie, 'bewaartermijn' => 'P5Y']);

		$xml = $this->generator->generate($object);

		$this->assertMatchesRegularExpression(
			'#<mdto:waardering>\s*<mdto:begripLabel>' . $label . '</mdto:begripLabel>\s*<mdto:begripCode>' . $code
			. '</mdto:begripCode>\s*<mdto:begripBegrippenlijst>\s*<mdto:verwijzingNaam>Waarderingen</mdto:verwijzingNaam>#',
			$xml
		);
	}

	/**
	 * Every archiefnominatie the codebase writes, and its Waarderingen term.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function appraisals(): array {
		return [
			'bewaren' => ['bewaren', 'B', 'Blijvend te bewaren'],
			'blijvend_bewaren' => ['blijvend_bewaren', 'B', 'Blijvend te bewaren'],
			'vernietigen' => ['vernietigen', 'V', 'Tijdelijk te bewaren'],
			'nog_niet_bepaald' => ['nog_niet_bepaald', 'N', 'Nader te bepalen'],
		];
	}

	/**
	 * A value outside the closed list is refused, not written as an invalid label.
	 */
	public function testAnAppraisalOutsideTheClosedListIsRefused(): void {
		$object = $this->createObjectEntity(uuid: 'w', retention: ['archiefnominatie' => 'misschien', 'bewaartermijn' => 'P5Y']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/retention\.archiefnominatie \(one of: /');

		$this->generator->generate($object);
	}

	/**
	 * Deviation 4: bewaartermijn is a termijnGegevens, with the end date when the record has one.
	 */
	public function testBewaartermijnIsATermijnGegevens(): void {
		$object = $this->createObjectEntity(
			uuid: 't',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P20Y', 'archiefactiedatum' => '2046-09-11']
		);

		$xml = $this->generator->generate($object);

		$this->assertMatchesRegularExpression(
			'#<mdto:bewaartermijn>\s*<mdto:termijnLooptijd>P20Y</mdto:termijnLooptijd>\s*'
			. '<mdto:termijnEinddatum>2046-09-11</mdto:termijnEinddatum>\s*</mdto:bewaartermijn>#',
			$xml
		);
	}

	/**
	 * A retention period that is not an xsd:duration is refused.
	 *
	 * `P2W` is the case that matters: PHP's DateInterval accepts it, the XSD does not.
	 */
	public function testABewaartermijnThatIsNotAnXsdDurationIsRefused(): void {
		$object = $this->createObjectEntity(uuid: 't', retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P2W']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/retention\.bewaartermijn \(an ISO-8601/');

		$this->generator->generate($object);
	}

	/**
	 * Deviation 3: informatiecategorie names its selectielijst, or says it is a selectielijst.
	 */
	public function testInformatiecategorieNamesItsSelectielijst(): void {
		$named = $this->createObjectEntity(
			uuid: 'c',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'classification' => 'A1',
				'selectielijstBron' => 'Selectielijst gemeenten 2020',
			]
		);
		$unnamed = $this->createObjectEntity(
			uuid: 'c',
			retention: ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P5Y', 'classification' => 'A1']
		);

		$this->assertStringContainsString(
			'<mdto:verwijzingNaam>Selectielijst gemeenten 2020</mdto:verwijzingNaam>',
			$this->generator->generate($named)
		);
		$this->assertStringContainsString(
			'<mdto:verwijzingNaam>' . MdtoXmlGenerator::CATEGORY_LIST_FALLBACK . '</mdto:verwijzingNaam>',
			$this->generator->generate($unnamed)
		);
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

		$generator = $this->makeGenerator(appConfig: $appConfig, eventMapper: $this->eventMapper);

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
	 * A RELEASED legal hold is not a restriction, so the unrecorded term is used.
	 *
	 * The XSD requires beperkingGebruik, so "no restriction known" is written
	 * as the standard's own "Nader te bepalen", never as the hold's "Overig".
	 */
	public function testAReleasedLegalHoldYieldsTheUnrecordedTerm(): void {
		$object = $this->createObjectEntity(
			uuid: 'hold-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'legalHold' => ['active' => false, 'history' => []],
			]
		);

		$xml = $this->generator->generate($object);

		$this->assertStringContainsString('<mdto:beperkingGebruik>', $xml);
		$this->assertStringContainsString('<mdto:begripLabel>Nader te bepalen</mdto:begripLabel>', $xml);
		$this->assertStringNotContainsString('<mdto:begripLabel>Overig</mdto:begripLabel>', $xml);
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

		$generator = $this->makeGenerator(appConfig: $this->appConfig, eventMapper: $eventMapper);
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

		$generator = $this->makeGenerator(appConfig: $this->appConfig, eventMapper: $eventMapper);
		$object = $this->createObjectEntity(
			uuid: 'order-uuid',
			retention: [
				'archiefnominatie' => 'bewaren',
				'bewaartermijn' => 'P5Y',
				'aggregationLevel' => 'Dossier',
				'toelichting' => 'Omschrijving',
				'classification' => 'A1',
				'temporalCoverage' => ['type' => 'Looptijd', 'start' => '2021-01-01'],
				'useRestriction' => 'Geen beperking',
			]
		);

		$xml = $generator->generate($object, [$this->file()]);

		$positions = [];
		foreach (
			[
				'<mdto:naam>',
				'<mdto:aggregatieniveau>',
				'<mdto:omschrijving>',
				'<mdto:dekkingInTijd>',
				'<mdto:event>',
				'<mdto:waardering>',
				'<mdto:bewaartermijn>',
				'<mdto:informatiecategorie>',
				'<mdto:heeftRepresentatie>',
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
	 * A well-formed file entry, as EdepotTransferService builds one.
	 *
	 * @return array<string, mixed> The file.
	 */
	private function file(): array {
		return [
			'name' => 'document.pdf',
			'size' => 1024,
			'format' => 'application/pdf',
			'checksum' => str_repeat('ab', 32),
			'checksumDate' => '2026-09-11T10:15:00+00:00',
		];
	}

	/**
	 * Count the whitespace-only text nodes directly under an element.
	 *
	 * @param \DOMElement $element The element.
	 *
	 * @return int The count.
	 */
	private function whitespaceNodes(\DOMElement $element): int {
		$count = 0;
		foreach ($element->childNodes as $node) {
			if ($node instanceof \DOMText && trim($node->textContent) === '') {
				$count++;
			}
		}

		return $count;
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
