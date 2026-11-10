<?php

/**
 * Two different answers, and the test that keeps them apart.
 *
 * 🔴 THIS IS THE BEHAVIOUR THE WHOLE CHANGE IS FOR. Before the boundary was
 * validated, a malformed file was walked into a flow with missing nodes and
 * reported as a successful import: a `sequenceFlow` with no `targetRef` became
 * an edge pointing at nothing, and the author was told nothing at all. "Your
 * file is malformed, at this element, on this line" and "we cannot express
 * this construct" have to be two answers, and a valid file carrying something
 * the engine has no equivalent for must still get the second one.
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
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Exception\BpmnImportRefused;
use OCA\OpenRegister\Exception\BpmnSchemaInvalid;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnExporter;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies XSD validation in both directions, and that it is its own answer.
 */
class BpmnSchemaValidationTest extends TestCase {

	/**
	 * The head every fixture shares.
	 *
	 * @var string
	 */
	private const HEAD = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"'
		. ' id="Definitions_1" targetNamespace="urn:openregister:test">' . "\n";

	/**
	 * A file that is XML, is BPMN-shaped, and is not valid BPMN: its one
	 * sequence flow never says where it goes.
	 *
	 * @return string The fixture.
	 */
	private function malformed(): string {
		return self::HEAD
			. '  <bpmn:process id="Process_1" isExecutable="false">' . "\n"
			. '    <bpmn:startEvent id="s1" name="Start"/>' . "\n"
			. '    <bpmn:serviceTask id="t1" name="Doe iets"/>' . "\n"
			. '    <bpmn:sequenceFlow id="f1" sourceRef="s1"/>' . "\n"
			. '  </bpmn:process>' . "\n"
			. '</bpmn:definitions>' . "\n";
	}//end malformed()

	/**
	 * The positive control: the same file with the missing attribute supplied.
	 *
	 * 🔑 WITHOUT THIS, THE REFUSAL TEST PROVES NOTHING. A validator that
	 * refuses every file passes the refusal case perfectly.
	 *
	 * @return string The fixture.
	 */
	private function corrected(): string {
		return self::HEAD
			. '  <bpmn:process id="Process_1" isExecutable="false">' . "\n"
			. '    <bpmn:startEvent id="s1" name="Start"/>' . "\n"
			. '    <bpmn:serviceTask id="t1" name="Doe iets"/>' . "\n"
			. '    <bpmn:sequenceFlow id="f1" sourceRef="s1" targetRef="t1"/>' . "\n"
			. '  </bpmn:process>' . "\n"
			. '</bpmn:definitions>' . "\n";
	}//end corrected()

	/**
	 * A perfectly valid BPMN file carrying a construct the engine cannot express.
	 *
	 * @return string The fixture.
	 */
	private function validButUnsupported(): string {
		return self::HEAD
			. '  <bpmn:process id="Process_1" isExecutable="false">' . "\n"
			. '    <bpmn:startEvent id="s1" name="Start"/>' . "\n"
			. '    <bpmn:subProcess id="sub1" name="Bij een fout" triggeredByEvent="true"/>' . "\n"
			. '    <bpmn:endEvent id="e1" name="Klaar"/>' . "\n"
			. '    <bpmn:sequenceFlow id="f1" sourceRef="s1" targetRef="e1"/>' . "\n"
			. '  </bpmn:process>' . "\n"
			. '</bpmn:definitions>' . "\n";
	}//end validButUnsupported()

	/**
	 * A valid file the importer refuses for a reason of its own: two processes.
	 *
	 * @return string The fixture.
	 */
	private function twoProcesses(): string {
		return self::HEAD
			. '  <bpmn:process id="Process_1" isExecutable="false">' . "\n"
			. '    <bpmn:startEvent id="s1" name="Start"/>' . "\n"
			. '  </bpmn:process>' . "\n"
			. '  <bpmn:process id="Process_2" isExecutable="false">' . "\n"
			. '    <bpmn:startEvent id="s2" name="Start"/>' . "\n"
			. '  </bpmn:process>' . "\n"
			. '</bpmn:definitions>' . "\n";
	}//end twoProcesses()

	/**
	 * A flow exercising the mapping rows the schema has opinions about.
	 *
	 * @return Flow The flow.
	 */
	private function flow(): Flow {
		$flow = new Flow();
		$flow->setUuid('7f1e2a10-0000-4000-8000-000000000042');
		$flow->setName('Bezwaar behandelen');
		$flow->setNodes(
			[
				['id' => 'start', 'name' => 'Start', 'type' => 'openregister.trigger-manual', 'position' => ['x' => 10, 'y' => 20]],
				['id' => 'sched', 'name' => 'Elke nacht', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 2 * * *']],
				['id' => 'obj', 'name' => 'Op object', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'created', 'register' => 'klanten']],
				['id' => 'kies', 'name' => 'Kies', 'type' => 'openregister.switch'],
				['id' => 'wacht', 'name' => 'Wacht op signaal', 'type' => 'openregister.await-signal'],
				['id' => 'pauze', 'name' => 'Pauze', 'type' => 'openregister.wait'],
				['id' => 'deel', 'name' => 'Deelflow', 'type' => 'openregister.sub-flow'],
				['id' => 'mail', 'name' => 'Stuur mail', 'type' => 'openregister.send-email', 'config' => ['to' => 'a@b.nl']],
				['id' => 'klaar', 'name' => 'Klaar', 'type' => 'openregister.end'],
			]
		);
		$flow->setEdges(
			[
				['id' => 'e1', 'from' => 'start', 'to' => 'kies'],
				['id' => 'e2', 'from' => 'kies', 'to' => 'mail', 'condition' => 'bedrag > 100'],
				['id' => 'e3', 'from' => 'mail', 'to' => 'klaar'],
			]
		);

		return $flow;
	}//end flow()

	/**
	 * The exporter, wired to the vendored schema set.
	 *
	 * @return FlowBpmnExporter The exporter.
	 */
	private function exporter(): FlowBpmnExporter {
		return new FlowBpmnExporter(vocabulary: new BpmnVocabulary(), validator: new BpmnSchemaValidator());
	}//end exporter()

	/**
	 * The importer, wired to the vendored schema set.
	 *
	 * @return FlowBpmnImporter The importer.
	 */
	private function importer(): FlowBpmnImporter {
		return new FlowBpmnImporter(vocabulary: new BpmnVocabulary(), validator: new BpmnSchemaValidator());
	}//end importer()

	/**
	 * 🔴 What we emit validates against the schema nobody edited.
	 *
	 * 🔑 THE EXPORTER IS BUILT WITH A DOUBLE THAT ACCEPTS EVERYTHING, and the
	 * output is then judged by the real validator. That is deliberate: the
	 * exporter validates its own output, so with the real validator inside it
	 * a serializer regression throws out of the CALL and the assertion below
	 * never runs. A red on the setup line says "something went wrong"; this
	 * one says which element of our output the standard rejects. The double is
	 * `onlyMethods`, so it cannot invent a method the real validator lacks.
	 *
	 * @return void
	 */
	public function testWhatWeExportValidatesAgainstTheUnmodifiedSchema(): void {
		$permissive = $this->getMockBuilder(BpmnSchemaValidator::class)
			->onlyMethods(['assertValid'])
			->getMock();

		$exporter = new FlowBpmnExporter(vocabulary: new BpmnVocabulary(), validator: $permissive);
		$xml = $exporter->export(flow: $this->flow());

		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml));

		$violation = (new BpmnSchemaValidator())->firstViolation(document: $document);

		$this->assertNull(
			$violation,
			sprintf('the export must validate; first violation: %s', json_encode($violation))
		);
	}//end testWhatWeExportValidatesAgainstTheUnmodifiedSchema()

	/**
	 * And the exporter refuses to hand out a document that does not validate.
	 *
	 * @return void
	 */
	public function testTheExporterRefusesToReturnADocumentThatDoesNotValidate(): void {
		$strict = $this->getMockBuilder(BpmnSchemaValidator::class)
			->onlyMethods(['assertValid'])
			->getMock();
		$strict->expects($this->once())
			->method('assertValid')
			->willThrowException(new BpmnSchemaInvalid(message: 'refused by the double', violationLine: 2, element: 'definitions'));

		$exporter = new FlowBpmnExporter(vocabulary: new BpmnVocabulary(), validator: $strict);

		$this->expectException(BpmnSchemaInvalid::class);
		$exporter->export(flow: $this->flow());
	}//end testTheExporterRefusesToReturnADocumentThatDoesNotValidate()

	/**
	 * 🔴 A malformed file is malformed, and says where.
	 *
	 * This is the case that used to read as a successful import.
	 *
	 * @return void
	 */
	public function testAMalformedFileIsRefusedNamingTheElementAndTheLine(): void {
		try {
			$this->importer()->import(xml: $this->malformed());
			$this->fail('a file that does not validate must never produce a flow');
		} catch (BpmnSchemaInvalid $invalid) {
			$this->assertSame('sequenceFlow', $invalid->getElement(), 'the answer must name the element');
			$this->assertSame(6, $invalid->getViolationLine(), 'the answer must name the line');
			$this->assertStringContainsString('targetRef', $invalid->getMessage());
			$this->assertStringContainsString('not valid BPMN 2.0.2', $invalid->getMessage());
		}
	}//end testAMalformedFileIsRefusedNamingTheElementAndTheLine()

	/**
	 * The malformed answer is not the unsupported-construct answer.
	 *
	 * @return void
	 */
	public function testAMalformedFileIsNotReportedAsAnUnsupportedConstruct(): void {
		$this->expectException(BpmnSchemaInvalid::class);

		try {
			$this->importer()->import(xml: $this->malformed());
		} catch (BpmnImportRefused $refused) {
			$this->fail(
				'a broken document must not be answered with a mapping report: '
				. 'that attributes XML problems to process constructs'
			);
		}
	}//end testAMalformedFileIsNotReportedAsAnUnsupportedConstruct()

	/**
	 * The positive control: the corrected file imports.
	 *
	 * @return void
	 */
	public function testTheCorrectedFileImports(): void {
		$result = $this->importer()->import(xml: $this->corrected());

		$this->assertCount(2, $result['flow']['nodes']);
		$this->assertSame('t1', $result['flow']['edges'][0]['to']);
	}//end testTheCorrectedFileImports()

	/**
	 * 🔴 A valid file we cannot fully express still gets the OTHER answer.
	 *
	 * @return void
	 */
	public function testAValidFileWithAConstructWeCannotExpressGetsTheOtherAnswer(): void {
		$result = $this->importer()->import(xml: $this->validButUnsupported());

		$this->assertArrayHasKey('flow', $result, 'a valid file must produce a flow, minus what we cannot express');

		$refusals = array_values(
			array_filter(
				$result['report']->jsonSerialize()['entries'],
				static fn (array $entry): bool => ($entry['verdict'] === BpmnMappingReport::REFUSED)
			)
		);

		$this->assertCount(1, $refusals);
		$this->assertSame('sub1', $refusals[0]['elementId'], 'a refusal an author cannot locate is a refusal they read as a bug');
		$this->assertSame('subProcess', $refusals[0]['kind']);
	}//end testAValidFileWithAConstructWeCannotExpressGetsTheOtherAnswer()

	/**
	 * Two processes in one valid file: the report's own refusal, as a control.
	 *
	 * @return void
	 */
	public function testAValidFileWithTwoProcessesIsRefusedByTheReportNotBySchema(): void {
		$this->expectException(BpmnImportRefused::class);
		$this->importer()->import(xml: $this->twoProcesses());
	}//end testAValidFileWithTwoProcessesIsRefusedByTheReportNotBySchema()

	/**
	 * 🔴 Validation happens BEFORE anything the importer decides.
	 *
	 * The ordering is what is asserted, and it is asserted by WHICH exception
	 * comes out. The same file, with the real validator, is refused by the
	 * importer's own "one process per file" rule (the control above). With a
	 * validator that refuses it, that later rule never gets to speak.
	 *
	 * The double uses `onlyMethods`, so it cannot invent a method the real
	 * validator lacks. `BpmnVocabulary` is final and cannot be doubled at all,
	 * which is why the order is proved this way rather than with a `never()`.
	 *
	 * @return void
	 */
	public function testTheImporterValidatesBeforeItMapsAnything(): void {
		$validator = $this->getMockBuilder(BpmnSchemaValidator::class)
			->onlyMethods(['assertValid'])
			->getMock();
		$validator->expects($this->once())
			->method('assertValid')
			->willThrowException(new BpmnSchemaInvalid(message: 'refused by the double', violationLine: 3, element: 'process'));

		$importer = new FlowBpmnImporter(vocabulary: new BpmnVocabulary(), validator: $validator);

		$this->expectException(BpmnSchemaInvalid::class);
		$importer->import(xml: $this->twoProcesses());
	}//end testTheImporterValidatesBeforeItMapsAnything()

	/**
	 * The validator reads the vendored set off disk, with no network.
	 *
	 * @return void
	 */
	public function testTheValidatorReadsTheVendoredRootSchema(): void {
		$validator = new BpmnSchemaValidator();

		$this->assertFileExists($validator->rootSchema());
		$this->assertStringEndsWith(BpmnSchemaValidator::ROOT_SCHEMA, $validator->rootSchema());
	}//end testTheValidatorReadsTheVendoredRootSchema()
}//end class
