<?php

declare(strict_types=1);

/**
 * TMLO MDTO export XSD conformance tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use DOMDocument;
use DOMElement;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Edepot\MdtoBestandGenerator;
use OCA\OpenRegister\Service\Edepot\MdtoDocumentWriter;
use OCA\OpenRegister\Service\Edepot\MdtoEventMapper;
use OCA\OpenRegister\Service\Edepot\MdtoPreconditions;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCA\OpenRegister\Service\Edepot\MdtoValueReader;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCA\OpenRegister\Service\TmloService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What `GET /api/tmlo/{register}/{schema}/{id}/export` produces must validate.
 *
 * This endpoint had its own MDTO implementation and its output was rejected by
 * the schema at the root element. It now shares the e-Depot generator, and this
 * holds that true from the endpoint's own side, so a change to either cannot
 * quietly break the other.
 */
class TmloMdtoExportXsdTest extends TestCase {

	/**
	 * The vendored schema, relative to this file.
	 */
	private const XSD = __DIR__ . '/../../../lib/Resources/mdto/MDTO-XML1.0.1.xsd';

	private TmloService $service;

	/**
	 * The external-entity loader in force before this test.
	 *
	 * @var callable|null
	 */
	private $previousEntityLoader = null;

	/**
	 * Build the service, under the entity loader a booted Nextcloud installs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// CI runs this suite inside a booted Nextcloud, whose XXE protection
		// makes libxml's external-entity loader return null for everything. A
		// validation that reads the schema FROM DISK passes locally and fails
		// there, so the condition is carried in the test.
		$this->previousEntityLoader = libxml_get_external_entity_loader();
		libxml_set_external_entity_loader(static fn (): mixed => null);

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
		$eventMapper->method('forObject')->willReturn(
			[['type' => 'Creatie', 'time' => '2026-01-01T09:00:00+00:00', 'actorName' => 'Jane Doe', 'actorId' => 'jdoe']]
		);

		$writer = new MdtoDocumentWriter();
		$sourceReader = new MdtoSourceReader(new MdtoValueReader());
		$bestandGenerator = new MdtoBestandGenerator($writer);
		$preconditions = new MdtoPreconditions($appConfig, $this->createMock(LoggerInterface::class), $sourceReader, $bestandGenerator);

		$this->service = new TmloService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(LoggerInterface::class),
			new MdtoXmlGenerator(
				$appConfig,
				$eventMapper,
				new MdtoSourceReader(new MdtoValueReader()),
				$writer,
				new MdtoBestandGenerator($writer),
				$preconditions
			)
		);
	}//end setUp()

	/**
	 * Restore the entity loader that was in force before this test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		libxml_set_external_entity_loader($this->previousEntityLoader);

		parent::tearDown();
	}//end tearDown()

	/**
	 * A full TMLO record exports as a document the schema accepts.
	 *
	 * @return void
	 */
	public function testSingleExportValidates(): void {
		$xml = $this->service->generateMdtoXml($this->tmloObject());

		$this->assertValidMdto(xml: $xml, what: 'single export');
	}

	/**
	 * A TMLO record with only the mandatory appraisal still validates.
	 *
	 * @return void
	 */
	public function testMinimalExportValidates(): void {
		$object = new ObjectEntity();
		$object->setUuid('4d1f0f4e-6b1a-4a5e-9a5c-70d3f6d4a111');
		$object->setName('Minimaal');
		$object->setTmlo(['archiefnominatie' => 'vernietigen', 'archiefstatus' => 'actief']);

		$this->assertValidMdto(xml: $this->service->generateMdtoXml($object), what: 'minimal export');
	}

	/**
	 * Every child of the batch envelope is a complete, valid MDTO document.
	 *
	 * The envelope itself is openregister's own element and is not claimed to
	 * be MDTO, so it is the children that are validated, each on its own.
	 *
	 * @return void
	 */
	public function testEveryDocumentInTheBatchEnvelopeValidates(): void {
		$xml = $this->service->generateBatchMdtoXml([$this->tmloObject(), $this->tmloObject('Tweede')]);

		$envelope = new DOMDocument();
		$this->assertTrue($envelope->loadXML($xml));
		// The LITERAL namespace, not the constant: asserting against the
		// constant under test compares a value with itself, and a change that
		// pointed the envelope back at the MDTO namespace would pass.
		$this->assertSame('https://www.openregister.app/mdto-export', $envelope->documentElement->namespaceURI);
		$this->assertNotSame(
			'https://www.nationaalarchief.nl/mdto',
			$envelope->documentElement->namespaceURI,
			'The envelope is openregister\'s own element and must not claim to be MDTO'
		);

		$children = 0;
		foreach ($envelope->documentElement->childNodes as $child) {
			if ($child instanceof DOMElement === false) {
				continue;
			}

			$children++;
			$document = new DOMDocument('1.0', 'UTF-8');
			$document->appendChild($document->importNode($child, true));
			$this->assertValidMdto(xml: (string)$document->saveXML(), what: 'batch child ' . $children);
		}

		$this->assertSame(2, $children, 'The envelope did not carry one document per object');
	}

	/**
	 * Assert a document validates, naming every schema error when it does not.
	 *
	 * @param string $xml The document.
	 * @param string $what What the document is, for the failure message.
	 *
	 * @return void
	 */
	private function assertValidMdto(string $xml, string $what): void {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$dom = new DOMDocument();
		$loaded = $dom->loadXML($xml);
		// schemaValidateSource over the schema's CONTENTS: reading the file is
		// plain PHP I/O, outside libxml's entity loader.
		$valid = ($loaded === true && $dom->schemaValidateSource((string)file_get_contents(self::XSD)) === true);

		$errors = [];
		foreach (libxml_get_errors() as $error) {
			$errors[] = 'line ' . $error->line . ': ' . trim($error->message);
		}

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($valid === false && $errors === []) {
			$errors[] = 'schemaValidate returned false without reporting an error';
		}

		$this->assertSame(
			[],
			$errors,
			'The ' . $what . " does not validate against MDTO-XML1.0.1.xsd:\n  "
			. implode("\n  ", $errors) . "\n\nDocument:\n" . $xml
		);
	}

	/**
	 * A record carrying the full TMLO field set.
	 *
	 * @param string $name The object's name.
	 *
	 * @return ObjectEntity The object.
	 */
	private function tmloObject(string $name = 'Besluit omgevingsvergunning'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('0b1e9c52-5f3a-4f9e-9d0a-2b6c1d7e8f90');
		$object->setName($name);
		$object->setTmlo(
			[
				'classification' => '1.1',
				'archiefnominatie' => 'blijvend_bewaren',
				'archiefactiedatum' => '2030-01-01',
				'archiefstatus' => 'semi_statisch',
				'bewaarTermijn' => 'P7Y',
				'vernietigingsCategorie' => '1.1.3',
			]
		);

		return $object;
	}
}
