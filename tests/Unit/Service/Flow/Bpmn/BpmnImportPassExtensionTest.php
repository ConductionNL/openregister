<?php

/**
 * Unit test for BpmnImportPass's extension reading: the openregister type and
 * config an element carries, with a config that is no JSON object read as none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnImportPass;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\BpmnImportPass
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport
 */
class BpmnImportPassExtensionTest extends TestCase {

	/**
	 * A pass over a process of three tasks: with a JSON config, with a config that is no object, and with none.
	 *
	 * @return array{0: BpmnImportPass, 1: array<int, DOMElement>}
	 */
	private function pass(): array {
		$xml = '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:openregister="https://openregister.app/schema/bpmn/1.0">'
			.'<bpmn:process id="p">'
			.'<bpmn:task id="a"><bpmn:extensionElements><openregister:type> openregister.send-email </openregister:type><openregister:config>{"to":"a@b.nl"}</openregister:config></bpmn:extensionElements></bpmn:task>'
			.'<bpmn:task id="b"><bpmn:extensionElements><openregister:config>"just text"</openregister:config></bpmn:extensionElements></bpmn:task>'
			.'<bpmn:task id="c"/>'
			.'text between'
			.'</bpmn:process></bpmn:definitions>';
		$document = new DOMDocument();
		$document->loadXML($xml);
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('bpmn', 'http://www.omg.org/spec/BPMN/20100524/MODEL');
		$xpath->registerNamespace('openregister', 'https://openregister.app/schema/bpmn/1.0');
		$pass = new BpmnImportPass(xpath: $xpath, report: new BpmnMappingReport());

		return [$pass, $pass->childElements(process: $xpath->query('//bpmn:process')->item(0))];
	}//end pass()

	/**
	 * Only element children are listed, in order.
	 *
	 * @return void
	 */
	public function testChildElementsAreTheElementsOnly(): void {
		[, $tasks] = $this->pass();
		$this->assertSame(['a', 'b', 'c'], array_map(fn (DOMElement $e): string => $e->getAttribute('id'), $tasks));
	}//end testChildElementsAreTheElementsOnly()

	/**
	 * A JSON object config is decoded, one that is no object reads as none, and a missing one is none.
	 *
	 * @return void
	 */
	public function testAConfigIsReadOnlyWhenItIsAJsonObject(): void {
		[$pass, $tasks] = $this->pass();

		$this->assertSame(['to' => 'a@b.nl'], $pass->extensionConfig(element: $tasks[0]));
		$this->assertNull($pass->extensionConfig(element: $tasks[1]));
		$this->assertNull($pass->extensionConfig(element: $tasks[2]));
	}//end testAConfigIsReadOnlyWhenItIsAJsonObject()

	/**
	 * The type is trimmed, and absent reads as empty.
	 *
	 * @return void
	 */
	public function testTheTypeIsTrimmed(): void {
		[$pass, $tasks] = $this->pass();

		$this->assertSame('openregister.send-email', $pass->extensionType(element: $tasks[0]));
		$this->assertSame('', $pass->extensionType(element: $tasks[2]));
	}//end testTheTypeIsTrimmed()
}//end class
