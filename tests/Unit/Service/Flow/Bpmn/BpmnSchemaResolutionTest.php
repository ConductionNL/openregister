<?php

/**
 * The schema set has to be readable where the product actually runs.
 *
 * 🔴 THIS IS THE TEST THE PRODUCTION BUG NEEDED. Nextcloud's `lib/base.php`
 * installs an external entity loader that returns null for every resource,
 * and that loader answers for the primary document as well as for the
 * entities it references. `DOMDocument::schemaValidate($path)` therefore
 * could not read `BPMN20.xsd` on any running instance: every BPMN export was
 * refused as invalid BPMN and every import as malformed, while a bare PHP
 * process, which installs no such loader, ran the whole suite green.
 *
 * 🔑 A HAPPY-PATH DOCUMENT IS NOT ENOUGH. `BPMN20.xsd` includes
 * `Semantic.xsd` and imports `BPMNDI.xsd`, which imports `DI.xsd` and
 * `DC.xsd`, and libxml resolves each of those through the same loader. So
 * what is asserted here is that every declared `schemaLocation` in the
 * vendored set resolves to a vendored file, not merely that one document
 * validates.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use DOMDocument;
use DOMXPath;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnExporter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the vendored schema set resolves where Nextcloud blocks it.
 */
class BpmnSchemaResolutionTest extends TestCase {

	/**
	 * The XML Schema namespace, for reading the vendored files.
	 *
	 * @var string
	 */
	private const XSD_NS = 'http://www.w3.org/2001/XMLSchema';

	/**
	 * The validator under test.
	 *
	 * @var BpmnSchemaValidator
	 */
	private BpmnSchemaValidator $validator;

	/**
	 * Install the entity loader a booted Nextcloud installs.
	 *
	 * Carrying the condition into the test is the whole point: without it a
	 * local run passes for the wrong reason, which is exactly what happened.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->validator = new BpmnSchemaValidator();
		libxml_set_external_entity_loader(static fn (): mixed => null);
	}//end setUp()

	/**
	 * Leave the process as a bare PHP process starts.
	 *
	 * PHP 8.4 can hand the previous resolver back, 8.3 and below cannot, and
	 * clearing it is the state this suite runs in otherwise.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		libxml_set_external_entity_loader(null);

		parent::tearDown();
	}//end tearDown()

	/**
	 * A flow whose export carries a diagram, so the DI imports are needed.
	 *
	 * @return Flow The flow.
	 */
	private function flow(): Flow {
		$flow = new Flow();
		$flow->setUuid('7f1e2a10-0000-4000-8000-000000000043');
		$flow->setName('Bezwaar behandelen');
		$flow->setNodes(
			[
				['id' => 'start', 'name' => 'Start', 'type' => 'openregister.trigger-manual'],
				['id' => 'mail', 'name' => 'Stuur mail', 'type' => 'openregister.send-email', 'config' => ['to' => 'a@b.nl']],
				['id' => 'klaar', 'name' => 'Klaar', 'type' => 'openregister.end'],
			]
		);
		$flow->setEdges(
			[
				['id' => 'e1', 'from' => 'start', 'to' => 'mail'],
				['id' => 'e2', 'from' => 'mail', 'to' => 'klaar'],
			]
		);

		return $flow;
	}//end flow()

	/**
	 * Every `schemaLocation` declared anywhere in the vendored set.
	 *
	 * Read with `loadXML()` on the bytes, because `load()` is the very call
	 * the null resolver breaks and this reader must work regardless.
	 *
	 * @return array<int, array{file: string, location: string}> The references.
	 */
	private function declaredReferences(): array {
		$references = [];

		foreach (array_keys(BpmnSchemaValidator::CHECKSUMS) as $file) {
			$source = file_get_contents($this->validator->schemaDirectory() . DIRECTORY_SEPARATOR . $file);
			$this->assertNotFalse($source, sprintf('the vendored %s must be readable', $file));

			$document = new DOMDocument();
			$this->assertTrue($document->loadXML((string)$source), sprintf('the vendored %s must parse', $file));

			$xpath = new DOMXPath($document);
			$xpath->registerNamespace('xsd', self::XSD_NS);

			$nodes = $xpath->query('//xsd:import[@schemaLocation]|//xsd:include[@schemaLocation]');
			$this->assertNotFalse($nodes);

			foreach ($nodes as $node) {
				$references[] = [
					'file'     => $file,
					'location' => $node->getAttribute('schemaLocation'),
				];
			}
		}

		return $references;
	}//end declaredReferences()

	/**
	 * 🔴 Every imported schema location resolves to a vendored file.
	 *
	 * A resolver that serves only the root schema validates nothing, and says
	 * so with "Invalid Schema" rather than with a violation an author could
	 * act on. This asserts the graph, not one document's luck.
	 *
	 * @return void
	 */
	public function testEveryDeclaredSchemaLocationResolvesToAVendoredFile(): void {
		$references = $this->declaredReferences();

		$this->assertGreaterThanOrEqual(
			5,
			count($references),
			'the vendored set declares five includes and imports; finding fewer means the reader missed them'
		);

		$reached = [];
		foreach ($references as $reference) {
			$resolved = $this->validator->resolveSchemaReference(systemId: $reference['location']);

			$this->assertNotNull(
				$resolved,
				sprintf(
					'%s references %s, and validation cannot read it: libxml resolves it through the '
					. 'entity loader and would report "Invalid Schema" for every document',
					$reference['file'],
					$reference['location']
				)
			);

			$this->assertFileExists((string)$resolved);
			$reached[basename((string)$resolved)] = true;
		}

		$names = array_keys($reached);
		sort($names);

		$this->assertSame(
			['BPMNDI.xsd', 'DC.xsd', 'DI.xsd', 'Semantic.xsd'],
			$names,
			'all four non-root files must be reachable from the set; an unreachable one is never loaded'
		);
	}//end testEveryDeclaredSchemaLocationResolvesToAVendoredFile()

	/**
	 * The root schema resolves by the absolute path libxml asks for.
	 *
	 * @return void
	 */
	public function testTheRootSchemaResolvesByItsAbsolutePathAndAsAFileUri(): void {
		$root = $this->validator->rootSchema();

		$this->assertSame(realpath($root), $this->validator->resolveSchemaReference(systemId: $root));
		$this->assertSame(realpath($root), $this->validator->resolveSchemaReference(systemId: 'file://' . $root));
	}//end testTheRootSchemaResolvesByItsAbsolutePathAndAsAFileUri()

	/**
	 * 🔴 Nothing outside the five vendored files resolves.
	 *
	 * The widening exists to read the schema set, and it must not become a way
	 * to read anything else or to reach the network.
	 *
	 * @return void
	 */
	public function testAReferenceOutsideTheVendoredSetDoesNotResolve(): void {
		$outside = [
			'/etc/passwd',
			'../../../../composer.json',
			'PROVENANCE.md',
			'http://www.omg.org/spec/BPMN/20100524/BPMN20.xsd',
			'https://example.org/evil.xsd',
			'',
		];

		foreach ($outside as $systemId) {
			$this->assertNull(
				$this->validator->resolveSchemaReference(systemId: $systemId),
				sprintf('%s is not part of the vendored schema set and must not be served', $systemId)
			);
		}
	}//end testAReferenceOutsideTheVendoredSetDoesNotResolve()

	/**
	 * 🔴 An export validates while Nextcloud's null resolver is in force.
	 *
	 * This is the end-to-end statement of the production bug: the exporter
	 * validates its own output, so under the null resolver it threw
	 * `BpmnSchemaInvalid` for a document that is perfectly valid BPMN.
	 *
	 * 🔑 THE EXPORTER IS BUILT WITH A PERMISSIVE DOUBLE and the output is
	 * judged afterwards by the real validator, so that a regression reddens
	 * the assertion below instead of throwing out of the export call. A red on
	 * a setup line says "something went wrong"; this one says the document is
	 * not validating. The double is `onlyMethods`, so it cannot invent a
	 * method the real validator lacks.
	 *
	 * @return void
	 */
	public function testAnExportValidatesUnderNextcloudsNullEntityResolver(): void {
		$permissive = $this->getMockBuilder(BpmnSchemaValidator::class)
			->onlyMethods(['assertValid'])
			->getMock();

		$exporter = new FlowBpmnExporter(vocabulary: new BpmnVocabulary(), validator: $permissive);

		$xml = $exporter->export(flow: $this->flow());

		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml));

		$violation = $this->validator->firstViolation(document: $document);

		$this->assertNull(
			$violation,
			sprintf(
				'a valid export must validate under the resolver every instance installs; got: %s',
				json_encode($violation)
			)
		);
	}//end testAnExportValidatesUnderNextcloudsNullEntityResolver()

	/**
	 * 🔴 Validation does not leave its own loader behind.
	 *
	 * The scoped loader is a widening, and a widening that outlives the call
	 * is a hole. After validating, the process must still refuse to load a
	 * local file, exactly as Nextcloud left it.
	 *
	 * @return void
	 */
	public function testValidationPutsNextcloudsResolverBack(): void {
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML('<definitions xmlns="http://www.omg.org/spec/BPMN/20100524/MODEL"/>'));

		$this->validator->firstViolation(document: $document);

		$probe    = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded   = $probe->load($this->validator->rootSchema(), LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$this->assertFalse(
			$loaded,
			'validation must restore the blocking resolver; leaving the scoped one installed '
			. 'would let any later XML parse read files Nextcloud refuses'
		);
	}//end testValidationPutsNextcloudsResolverBack()

	/**
	 * And with no resolver installed, none is installed afterwards either.
	 *
	 * @return void
	 */
	public function testValidationRestoresTheBareProcessStateToo(): void {
		libxml_set_external_entity_loader(null);

		$document = new DOMDocument();
		$this->assertTrue($document->loadXML('<definitions xmlns="http://www.omg.org/spec/BPMN/20100524/MODEL"/>'));

		$this->validator->firstViolation(document: $document);

		$probe = new DOMDocument();
		$this->assertTrue(
			$probe->load($this->validator->rootSchema(), LIBXML_NONET),
			'a process that could read local files must still be able to after validating'
		);
	}//end testValidationRestoresTheBareProcessStateToo()
}//end class
