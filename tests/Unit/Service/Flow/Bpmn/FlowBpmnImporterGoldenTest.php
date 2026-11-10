<?php

/**
 * Golden test for FlowBpmnImporter: import() and importStrictly() over every
 * refusal, every element kind, extension elements and diagram positions.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use OCA\OpenRegister\Exception\BpmnImportRefused;
use OCA\OpenRegister\Exception\BpmnSchemaInvalid;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnExporter;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter
 */
class FlowBpmnImporterGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-bpmn-importer-golden.json';

	/**
	 * @var FlowBpmnImporter
	 */
	private FlowBpmnImporter $importer;

	protected function setUp(): void {
		$this->importer = new FlowBpmnImporter(new BpmnVocabulary(), new BpmnSchemaValidator());
	}//end setUp()

	private static function definitions(string $body, string $diagram = ''): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<bpmn:definitions xmlns:bpmn="' . FlowBpmnExporter::NS_BPMN . '"'
			. ' xmlns:bpmndi="' . FlowBpmnExporter::NS_BPMNDI . '"'
			. ' xmlns:dc="' . FlowBpmnExporter::NS_DC . '"'
			. ' xmlns:di="' . FlowBpmnExporter::NS_DI . '"'
			. ' xmlns:' . BpmnVocabulary::EXTENSION_PREFIX . '="' . BpmnVocabulary::EXTENSION_NS . '"'
			. ' id="D1" targetNamespace="urn:x">' . "\n"
			. $body . "\n" . $diagram . "\n"
			. '</bpmn:definitions>';
	}//end definitions()

	private static function extension(string $type, ?string $config = null): string {
		$prefix = BpmnVocabulary::EXTENSION_PREFIX;
		$xml = '<bpmn:extensionElements><' . $prefix . ':type>' . $type . '</' . $prefix . ':type>';
		if ($config !== null) {
			$xml .= '<' . $prefix . ':config>' . htmlspecialchars($config, ENT_XML1) . '</' . $prefix . ':config>';
		}

		return $xml . '</bpmn:extensionElements>';
	}//end extension()

	private static function foreign(): string {
		return self::definitions(
			'<bpmn:process id="P1" name="Ingekocht proces" isExecutable="true">'
			. '<bpmn:startEvent id="s1" name=" Start "/>'
			. '<bpmn:startEvent id="s2" name="Timer"><bpmn:timerEventDefinition/></bpmn:startEvent>'
			. '<bpmn:startEvent id="s3" name="Cond"><bpmn:conditionalEventDefinition><bpmn:condition/></bpmn:conditionalEventDefinition></bpmn:startEvent>'
			. '<bpmn:startEvent id="s4" name="Msg"><bpmn:messageEventDefinition/></bpmn:startEvent>'
			. '<bpmn:userTask id="u1" name="Beoordeel"/>'
			. '<bpmn:serviceTask id="t1" name="Send email"/>'
			. '<bpmn:scriptTask id="t2"/>'
			. '<bpmn:exclusiveGateway id="g1"/>'
			. '<bpmn:inclusiveGateway id="g2"/>'
			. '<bpmn:parallelGateway id="g3"/>'
			. '<bpmn:intermediateCatchEvent id="c1"><bpmn:messageEventDefinition/></bpmn:intermediateCatchEvent>'
			. '<bpmn:intermediateCatchEvent id="c2"><bpmn:timerEventDefinition/></bpmn:intermediateCatchEvent>'
			. '<bpmn:intermediateThrowEvent id="c3"><bpmn:messageEventDefinition/></bpmn:intermediateThrowEvent>'
			. '<bpmn:callActivity id="a1" name="Sub"/>'
			. '<bpmn:boundaryEvent id="b1" attachedToRef="t1"><bpmn:timerEventDefinition/></bpmn:boundaryEvent>'
			. '<bpmn:transaction id="x1" name="Boeking"/>'
			. '<bpmn:subProcess id="x2" triggeredByEvent="true"/>'
			. '<bpmn:subProcess id="x3"/>'
			. '<bpmn:endEvent id="e1" name="Einde"/>'
			. '<bpmn:endEvent id="e2"><bpmn:terminateEventDefinition/></bpmn:endEvent>'
			. '<bpmn:endEvent id="e3"><bpmn:messageEventDefinition/></bpmn:endEvent>'
			. '<bpmn:sequenceFlow id="f1" sourceRef=" s1 " targetRef="u1"/>'
			. '<bpmn:sequenceFlow id="f2" sourceRef="u1" targetRef="g1">'
			. '<bpmn:conditionExpression> json.ok == true </bpmn:conditionExpression></bpmn:sequenceFlow>'
			. '<bpmn:sequenceFlow id="f3" sourceRef="g1" targetRef="e1"/>'
			. '<bpmn:textAnnotation id="n1"><bpmn:text>note</bpmn:text></bpmn:textAnnotation>'
			. '</bpmn:process>'
		);
	}//end foreign()

	private static function ours(): string {
		return self::definitions(
			'<bpmn:process id="P1" name="Eigen" isExecutable="true">'
			. '<bpmn:startEvent id="s1" name="Start">' . self::extension('openregister.trigger-manual') . '</bpmn:startEvent>'
			. '<bpmn:serviceTask id="t1" name="Mail">'
			. self::extension('openregister.send-email', '{"to":"{{email}}","subject":"Hi"}') . '</bpmn:serviceTask>'
			. '<bpmn:serviceTask id="t2" name="Bad config">' . self::extension('openregister.set-fields', '{not json') . '</bpmn:serviceTask>'
			. '<bpmn:serviceTask id="t3" name="Scalar config">' . self::extension('openregister.set-fields', '"text"') . '</bpmn:serviceTask>'
			. '<bpmn:exclusiveGateway id="g1">' . self::extension(' openregister.route ') . '</bpmn:exclusiveGateway>'
			. '<bpmn:transaction id="x1">' . self::extension('openregister.wait') . '</bpmn:transaction>'
			. '<bpmn:serviceTask id="t4">' . self::extension('') . '</bpmn:serviceTask>'
			. '<bpmn:endEvent id="e1">' . self::extension('openregister.end', '{}') . '</bpmn:endEvent>'
			. '<bpmn:sequenceFlow id="f1" sourceRef="s1" targetRef="t1"/>'
			. '<bpmn:sequenceFlow id="f2" sourceRef="t1" targetRef="e1"/>'
			. '</bpmn:process>',
			'<bpmndi:BPMNDiagram id="DI1"><bpmndi:BPMNPlane id="PL1" bpmnElement="P1">'
			. '<bpmndi:BPMNShape id="S_s1" bpmnElement="s1"><dc:Bounds x="10" y="20" width="36" height="36"/></bpmndi:BPMNShape>'
			. '<bpmndi:BPMNShape id="S_t1" bpmnElement="t1"><dc:Bounds x="200.5" y="40" width="100" height="80"/></bpmndi:BPMNShape>'
			. '</bpmndi:BPMNPlane></bpmndi:BPMNDiagram>'
		);
	}//end ours()

	private static function cases(): array {
		$minimal = '<bpmn:process id="P1" isExecutable="false"><bpmn:startEvent id="s1"/></bpmn:process>';

		return [
			'empty' => '',
			'blank' => "  \n ",
			'not xml' => '<bpmn:definitions',
			'not bpmn' => '<?xml version="1.0"?><foo/>',
			'no process' => self::definitions(''),
			'two processes' => self::definitions($minimal . str_replace(['P1', 's1'], ['P2', 's2'], $minimal)),
			'minimal unnamed' => self::definitions($minimal),
			'empty process' => self::definitions('<bpmn:process id="P1" name="Leeg" isExecutable="false"/>'),
			'collaboration' => self::definitions(
				'<bpmn:collaboration id="C1"><bpmn:participant id="pa1" processRef="P1"/></bpmn:collaboration>' . $minimal
			),
			'foreign' => self::foreign(),
			'ours' => self::ours(),
			'many nodes laid out' => self::definitions(
				'<bpmn:process id="P1" isExecutable="false">'
				. implode('', array_map(static fn (int $n): string => '<bpmn:serviceTask id="n' . $n . '"/>', range(1, 8)))
				. '</bpmn:process>'
			),
		];
	}//end cases()

	private function outcome(string $xml, bool $strict): array {
		try {
			$result = $this->importer->import($xml);
			if ($strict === true) {
				$result = $this->importer->importStrictly($xml);
			}
		} catch (BpmnSchemaInvalid $e) {
			return ['error' => get_class($e), 'message' => $e->getMessage(), 'line' => $e->getViolationLine(), 'element' => $e->getElement()];
		} catch (BpmnImportRefused $e) {
			return ['error' => get_class($e), 'message' => $e->getMessage(), 'report' => $e->getReport()?->jsonSerialize()];
		} catch (Throwable $e) {
			return ['error' => get_class($e), 'message' => $e->getMessage()];
		}

		return ['flow' => $result['flow'], 'report' => $result['report']->jsonSerialize()];
	}//end outcome()

	public function testFlowBpmnImporterMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $xml) {
			$actual['import: ' . $name] = $this->outcome($xml, false);
			$actual['importStrictly: ' . $name] = $this->outcome($xml, true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testFlowBpmnImporterMatchesGolden()
}//end class
