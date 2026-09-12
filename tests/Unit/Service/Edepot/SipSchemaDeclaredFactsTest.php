<?php

declare(strict_types=1);

/**
 * SIP schema-declared facts tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use DateTime;
use DOMDocument;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ObjectArchivalAnnotation;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\Edepot\MdtoBestandGenerator;
use OCA\OpenRegister\Service\Edepot\MdtoDocumentWriter;
use OCA\OpenRegister\Service\Edepot\MdtoEventMapper;
use OCA\OpenRegister\Service\Edepot\MdtoPreconditions;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCA\OpenRegister\Service\Edepot\MdtoValueReader;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCA\OpenRegister\Service\Edepot\SipPackageBuilder;
use OCP\IAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * A fact a SCHEMA declares must reach the document inside the SIP.
 *
 * This drives the real packaging path, not the reader: SipPackageBuilder
 * through MdtoXmlGenerator, MdtoSourceReader and ObjectArchivalAnnotation, with
 * only the schema source and the temp manager stubbed. That level matters,
 * because the defect it guards against was invisible to every unit test of the
 * reader: the annotation block is derived while RENDERING and never persisted,
 * so an object loaded straight from the mapper, which is how the transfer path
 * loads it, carried none of the facts its schema declared. The object's GET
 * showed all three while the SIP showed none.
 */
class SipSchemaDeclaredFactsTest extends TestCase {

	/**
	 * The vendored schema, relative to this file.
	 */
	private const XSD = __DIR__ . '/../../../../lib/Resources/mdto/MDTO-XML1.0.1.xsd';

	/**
	 * The external-entity loader in force before this test.
	 *
	 * @var callable|null
	 */
	private $previousEntityLoader = null;

	private string $tempFile = '';

	/**
	 * Install the entity loader a booted Nextcloud installs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->previousEntityLoader = libxml_get_external_entity_loader();
		libxml_set_external_entity_loader(static fn (): mixed => null);
	}//end setUp()

	/**
	 * Restore the loader and remove the package.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		libxml_set_external_entity_loader($this->previousEntityLoader);
		if ($this->tempFile !== '' && file_exists($this->tempFile) === true) {
			@unlink($this->tempFile);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The SIP's MDTO document carries what the schema declared.
	 *
	 * @return void
	 */
	public function testTheSipCarriesTheFactsTheSchemaDeclares(): void {
		$xml = $this->mdtoInPackage();

		$this->assertStringContainsString('<mdto:aggregatieniveau>', $xml);
		$this->assertStringContainsString('<mdto:begripLabel>Dossier</mdto:begripLabel>', $xml);

		$this->assertStringContainsString('<mdto:dekkingInTijd>', $xml);
		$this->assertStringContainsString(
			'<mdto:dekkingInTijdBegindatum>2021-01-01</mdto:dekkingInTijdBegindatum>',
			$xml
		);
		$this->assertStringContainsString(
			'<mdto:dekkingInTijdEinddatum>2021-12-31</mdto:dekkingInTijdEinddatum>',
			$xml
		);

		// The schema RECORDED a restriction, so the document must not fall back
		// to the term that means nobody recorded one.
		$this->assertStringContainsString('<mdto:begripLabel>Geen beperking</mdto:begripLabel>', $xml);
		$this->assertStringNotContainsString('<mdto:begripLabel>Nader te bepalen</mdto:begripLabel>', $xml);
		$this->assertStringContainsString('Openbaar na toetsing', $xml);
	}//end testTheSipCarriesTheFactsTheSchemaDeclares()

	/**
	 * And the document it carries still validates.
	 *
	 * @return void
	 */
	public function testThatDocumentStillValidates(): void {
		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($this->mdtoInPackage()));

		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$valid = $dom->schemaValidateSource((string)file_get_contents(self::XSD));
		$errors = [];
		foreach (libxml_get_errors() as $error) {
			$errors[] = 'line ' . $error->line . ': ' . trim($error->message);
		}

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$this->assertTrue($valid, "The packaged document does not validate:\n  " . implode("\n  ", $errors));
	}//end testThatDocumentStillValidates()

	/**
	 * Build a real SIP and return the MDTO document inside it.
	 *
	 * @return string The document.
	 */
	private function mdtoInPackage(): string {
		$this->tempFile = (string)tempnam(sys_get_temp_dir(), 'sipfacts') . '.zip';

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn($this->tempFile);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$builder = new SipPackageBuilder(
			$this->generator(),
			$appConfig,
			$tempManager,
			new NullLogger()
		);

		$result = $builder->build('transfer-facts', [['object' => $this->object(), 'files' => []]], 0, 'zip');

		$zip = new ZipArchive();
		$this->assertTrue($zip->open($result[0]) === true, 'The SIP could not be opened');
		$xml = (string)$zip->getFromName('objects/decl-uuid/mdto.xml');
		$zip->close();
		@unlink($result[0]);

		$this->assertNotSame('', $xml, 'The SIP carries no MDTO document for the object');

		return $xml;
	}//end mdtoInPackage()

	/**
	 * The real generator, over a schema source that declares the three facts.
	 *
	 * @return MdtoXmlGenerator The generator.
	 */
	private function generator(): MdtoXmlGenerator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['openregister', 'organisation_identifier', '', 'ORG-001'],
				['openregister', 'organisation_identifier', 'OpenRegister', 'ORG-001'],
				['openregister', 'organisation_name', '', 'Gemeente Voorbeeld'],
				['openregister', 'organisation_name', 'OpenRegister', 'Gemeente Voorbeeld'],
			]
		);

		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P10Y'],
					'aggregationLevel' => 'Dossier',
					'useRestriction' => ['type' => 'Geen beperking', 'description' => 'Openbaar na toetsing'],
					'temporalCoverage' => [
						'type' => 'Looptijd',
						'startProperty' => 'startdatum',
						'endProperty' => 'einddatum',
					],
				],
			]
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$annotations = new ObjectArchivalAnnotation(
			$schemaMapper,
			new RetentionEvaluator(logger: new NullLogger()),
			new NullLogger()
		);

		$eventMapper = $this->createMock(MdtoEventMapper::class);
		$eventMapper->method('forObject')->willReturn([]);

		$writer = new MdtoDocumentWriter();
		$sourceReader = new MdtoSourceReader(new MdtoValueReader(), $annotations);
		$bestandGenerator = new MdtoBestandGenerator($writer);

		return new MdtoXmlGenerator(
			$appConfig,
			$eventMapper,
			$sourceReader,
			$writer,
			$bestandGenerator,
			new MdtoPreconditions($appConfig, new NullLogger(), $sourceReader, $bestandGenerator)
		);
	}//end generator()

	/**
	 * An object as the transfer path loads it: no rendered annotation block,
	 * and no per-object override of the three facts.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getUuid', 'getObject', 'getSchema'])
			->addMethods(['getRetention', 'getTmlo', 'getCreated'])
			->getMock();
		$object->method('getUuid')->willReturn('decl-uuid');
		$object->method('jsonSerialize')->willReturn(['uuid' => 'decl-uuid']);
		$object->method('getObject')->willReturn(
			['title' => 'Besluit', 'startdatum' => '2021-01-01', 'einddatum' => '2021-12-31']
		);
		$object->method('getSchema')->willReturn('22');
		$object->method('getCreated')->willReturn(new DateTime('2026-01-01T00:00:00+00:00'));
		// Only the facts RetentionService writes. Nothing here declares an
		// aggregation level, a use restriction or a coverage: the schema does.
		$object->method('getRetention')->willReturn(['archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P10Y']);
		$object->method('getTmlo')->willReturn([]);

		return $object;
	}//end object()
}
