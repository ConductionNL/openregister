<?php

declare(strict_types=1);

/**
 * MdtoXmlGenerator XSD conformance tests
 *
 * Validates generated documents against the vendored MDTO-XML 1.0.1 XSD.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use DOMDocument;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\MdtoBestandGenerator;
use OCA\OpenRegister\Service\Edepot\MdtoDocumentWriter;
use OCA\OpenRegister\Service\Edepot\MdtoEventMapper;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The guard behind the spec's claim that MDTO output validates against the XSD.
 *
 * Every case builds a real document through the real generator and the real
 * source reader, then hands it to libxml's schema validator. A failure message
 * carries libxml's own error lines, so it names the element that broke rather
 * than reporting a bare `false`.
 */
class MdtoXmlGeneratorXsdTest extends TestCase {

	/**
	 * The vendored schema, relative to this file.
	 */
	private const XSD = __DIR__ . '/../../../../lib/Resources/mdto/MDTO-XML1.0.1.xsd';

	/**
	 * The schema's recorded provenance.
	 */
	private const VERSION_FILE = __DIR__ . '/../../../../lib/Resources/mdto/version.json';

	/**
	 * The Nationaal Archief's own example documents.
	 */
	private const EXAMPLES = __DIR__ . '/../../../fixtures/mdto';

	/**
	 * Representative objects: minimal, with files, with every optional element.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: array<string,mixed>, 2: list<array<string,mixed>>, 3: list<array<string,mixed>>}>
	 */
	public static function representativeObjects(): array {
		$base = ['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P20Y', 'classification' => 'A1'];

		$file = [
			'name' => 'besluit.pdf',
			'size' => 20480,
			'format' => 'application/pdf',
			'checksum' => str_repeat('ab', 32),
			'checksumDate' => '2026-09-11T10:15:00+00:00',
		];

		$event = [
			'type' => 'Creatie',
			'time' => '2026-01-01T09:00:00+00:00',
			'actorName' => 'Jane Doe',
			'actorId' => 'jdoe',
		];

		return [
			'minimal, no files, no optional elements' => [$base, [], [], []],
			'no classification, so informatiecategorie has no source' => [
				['archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P5Y'],
				[],
				[],
				[],
			],
			'with one file' => [$base, [], [$file], []],
			'with two files' => [
				$base,
				[],
				[
					$file,
					[
						'name' => 'bijlage.docx',
						'size' => 1,
						'format' => 'application/msword; charset=binary',
						'checksum' => str_repeat('CD', 32),
						'checksumDate' => '2026-09-11T10:15:00Z',
					],
				],
				[],
			],
			'every optional element, legal hold as the restriction' => [
				$base + [
					'archiefactiedatum' => '2046-09-11',
					'selectielijstBron' => 'Selectielijst gemeenten en intergemeentelijke organen 2020',
					'toelichting' => 'Dossier bij besluit 2026/14',
					'aggregationLevel' => 'Dossier',
					'temporalCoverage' => ['type' => 'Looptijd', 'start' => '2021-01-01', 'end' => '2021-12-31'],
					'legalHold' => ['active' => true, 'reason' => 'Lopend bezwaar', 'placedDate' => '2026-03-04T10:11:12+00:00'],
				],
				[],
				[$file],
				[$event, ['type' => 'Wijziging', 'time' => '2026-02-02T09:00:00+00:00', 'actorName' => null, 'actorId' => null]],
			],
			'declared restriction and TMLO-sourced aggregation level' => [
				$base + ['useRestriction' => ['type' => 'Geen beperking', 'description' => 'Openbaar na toetsing']],
				['aggregatieniveau' => ['label' => 'Archiefstuk', 'code' => 'AS']],
				[],
				[$event],
			],
			'nog niet bepaald waardering' => [
				['archiefnominatie' => 'nog_niet_bepaald', 'bewaartermijn' => 'P1Y'],
				[],
				[],
				[],
			],
		];
	}//end representativeObjects()

	/**
	 * The representative objects that carry files, for the bestand documents.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: array<string,mixed>, 2: list<array<string,mixed>>, 3: list<array<string,mixed>>}>
	 */
	public static function representativeObjectsWithFiles(): array {
		return array_filter(
			self::representativeObjects(),
			static fn (array $case): bool => $case[2] !== []
		);
	}//end representativeObjectsWithFiles()

	/**
	 * Every representative object produces a document the XSD accepts.
	 *
	 * @param array<string,mixed> $retention The retention block.
	 * @param array<string,mixed> $tmlo The TMLO block.
	 * @param list<array<string,mixed>> $files The file entries.
	 * @param list<array<string,mixed>> $events The events the mapper returns.
	 *
	 * @return void
	 */
	#[DataProvider('representativeObjects')]
	public function testGeneratedDocumentValidatesAgainstTheMdtoXsd(
		array $retention,
		array $tmlo,
		array $files,
		array $events,
	): void {
		$xml = $this->generator(events: $events)->generate(
			$this->objectEntity(retention: $retention, tmlo: $tmlo),
			$files
		);

		$this->assertValidMdto(xml: $xml, what: 'informatieobject');
	}//end testGeneratedDocumentValidatesAgainstTheMdtoXsd()

	/**
	 * Every file's own `bestand` document validates too.
	 *
	 * @param array<string,mixed> $retention The retention block.
	 * @param array<string,mixed> $tmlo The TMLO block.
	 * @param list<array<string,mixed>> $files The file entries.
	 * @param list<array<string,mixed>> $events The events the mapper returns.
	 *
	 * @return void
	 */
	#[DataProvider('representativeObjectsWithFiles')]
	public function testEveryBestandDocumentValidatesAgainstTheMdtoXsd(
		array $retention,
		array $tmlo,
		array $files,
		array $events,
	): void {
		$generator = $this->generator(events: $events);
		$object = $this->objectEntity(retention: $retention, tmlo: $tmlo);

		foreach ($files as $file) {
			$this->assertValidMdto(xml: $generator->generateBestand($object, $file), what: 'bestand ' . $file['name']);
		}
	}//end testEveryBestandDocumentValidatesAgainstTheMdtoXsd()

	/**
	 * Positive control: the Nationaal Archief's own example documents validate.
	 *
	 * Paired with the negative control below, this shows the harness accepts
	 * a document the publisher calls valid and rejects one that is not.
	 *
	 * @return void
	 */
	public function testThePublishersOwnExamplesValidate(): void {
		$examples = glob(self::EXAMPLES . '/*.xml');

		$this->assertNotEmpty($examples, 'No official example documents found under ' . self::EXAMPLES);
		foreach ($examples as $example) {
			$this->assertValidMdto(xml: (string)file_get_contents($example), what: basename($example));
		}
	}//end testThePublishersOwnExamplesValidate()

	/**
	 * Negative control: the validator really rejects a non-conforming document.
	 *
	 * Without this, a validator that could not load the schema, or that
	 * accepted anything, would make every case above pass for no reason.
	 *
	 * @return void
	 */
	public function testTheValidatorRejectsANonConformingDocument(): void {
		$errors = $this->schemaErrors(
			xml: '<?xml version="1.0" encoding="UTF-8"?>'
			. '<mdto:MDTO xmlns:mdto="https://www.nationaalarchief.nl/mdto">'
			. '<mdto:informatieobject><mdto:naam>zonder identificatie</mdto:naam></mdto:informatieobject>'
			. '</mdto:MDTO>'
		);

		$this->assertNotSame([], $errors, 'A document missing identificatie was accepted, so the validator is not validating');
	}//end testTheValidatorRejectsANonConformingDocument()

	/**
	 * The vendored schema is the one version.json says it is.
	 *
	 * The file is what the export is held to. An edit to it, even a well-meant
	 * one, silently moves the goalposts, so its checksum is pinned.
	 *
	 * @return void
	 */
	public function testTheVendoredXsdMatchesItsRecordedChecksum(): void {
		$recorded = json_decode((string)file_get_contents(self::VERSION_FILE), true);

		$this->assertIsArray($recorded);
		$this->assertSame($recorded['sha256'], hash_file('sha256', self::XSD));
	}//end testTheVendoredXsdMatchesItsRecordedChecksum()

	/**
	 * Assert a document validates, naming every schema error when it does not.
	 *
	 * @param string $xml The document.
	 * @param string $what What the document is, for the failure message.
	 *
	 * @return void
	 */
	private function assertValidMdto(string $xml, string $what): void {
		$errors = $this->schemaErrors(xml: $xml);

		$this->assertSame(
			[],
			$errors,
			'The ' . $what . " document does not validate against MDTO-XML1.0.1.xsd:\n  "
			. implode("\n  ", $errors) . "\n\nDocument:\n" . $xml
		);
	}//end assertValidMdto()

	/**
	 * Validate a document and return libxml's error lines.
	 *
	 * @param string $xml The document.
	 *
	 * @return list<string> Empty when the document is valid.
	 */
	private function schemaErrors(string $xml): array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$dom = new DOMDocument();
		$loaded = $dom->loadXML($xml);
		$valid = ($loaded === true && $dom->schemaValidate(self::XSD) === true);

		$errors = [];
		foreach (libxml_get_errors() as $error) {
			$errors[] = 'line ' . $error->line . ': ' . trim($error->message);
		}

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($valid === false && $errors === []) {
			$errors[] = 'schemaValidate returned false without reporting an error';
		}

		return $errors;
	}//end schemaErrors()

	/**
	 * Build a generator with a real source reader and a stubbed event history.
	 *
	 * @param list<array<string,mixed>> $events The events the mapper returns.
	 *
	 * @return MdtoXmlGenerator The generator.
	 */
	private function generator(array $events): MdtoXmlGenerator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['openregister', 'organisation_identifier', '', 'ORG-001'],
				['openregister', 'organisation_identifier', 'OpenRegister', 'ORG-001'],
				['openregister', 'organisation_name', '', 'Gemeente Voorbeeld'],
				['openregister', 'organisation_name', 'OpenRegister', 'Gemeente Voorbeeld'],
			]
		);

		$eventMapper = $this->createMock(MdtoEventMapper::class);
		$eventMapper->method('forObject')->willReturn($events);

		$writer = new MdtoDocumentWriter();

		return new MdtoXmlGenerator(
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$eventMapper,
			new MdtoSourceReader(),
			$writer,
			new MdtoBestandGenerator($writer)
		);
	}//end generator()

	/**
	 * Build a mock ObjectEntity.
	 *
	 * @param array<string,mixed> $retention The retention block.
	 * @param array<string,mixed> $tmlo The TMLO block.
	 *
	 * @return ObjectEntity&MockObject The mock.
	 */
	private function objectEntity(array $retention, array $tmlo): ObjectEntity&MockObject {
		$object = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getUuid', 'getObject'])
			->addMethods(['getRetention', 'getTmlo'])
			->getMock();
		$object->method('getUuid')->willReturn('0b1e9c52-5f3a-4f9e-9d0a-2b6c1d7e8f90');
		$object->method('getObject')->willReturn(['title' => 'Besluit omgevingsvergunning Kerkstraat 1']);
		$object->method('getRetention')->willReturn($retention);
		$object->method('getTmlo')->willReturn($tmlo);

		return $object;
	}//end objectEntity()
}//end class
