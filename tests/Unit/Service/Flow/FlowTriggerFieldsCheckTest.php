<?php

/**
 * Publishing a flow whose update trigger watches a property its schema lacks is refused, naming it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowTriggerFieldsCheck;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerFieldsCheck
 * @uses \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class FlowTriggerFieldsCheckTest extends TestCase {

	/**
	 * A check over register `zaken` holding schema 7 `melding` and schema 8 `aanvraag` (another app's `melding` lives elsewhere).
	 *
	 * @return FlowTriggerFieldsCheck
	 */
	private function check(): FlowTriggerFieldsCheck {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if ((string)$id !== 'zaken') {
					throw new DoesNotExistException('no such register');
				}

				$register = new Register();
				$register->setSchemas([7, 8]);

				return $register;
			}
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				$schema->setSlug(((int)$id === 7) ? 'melding' : 'aanvraag');
				$schema->setProperties(((int)$id === 7) ? ['omschrijving' => ['type' => 'string'], 'categorie' => ['type' => 'string']] : ['status' => ['type' => 'string']]);

				return $schema;
			}
		);

		return new FlowTriggerFieldsCheck($registers, $schemas);
	}//end check()

	/**
	 * A graph with one update trigger watching these fields.
	 *
	 * @param array<int, string> $fields   The watched fields.
	 * @param string             $register The register.
	 * @param string             $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	private function graph(array $fields, string $register = 'zaken', string $schema = 'melding'): array {
		return [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'object.updated', 'register' => $register, 'schema' => $schema, 'changedFields' => $fields]],
			],
			'edges' => [],
		];
	}//end graph()

	/**
	 * Watching properties the schema has passes.
	 *
	 * @return void
	 */
	public function testKnownPropertiesPass(): void {
		$this->check()->refuseUnknownChangedFields(flowId: 'f1', graph: $this->graph(['omschrijving', 'categorie']));
		$this->addToAssertionCount(1);
	}//end testKnownPropertiesPass()

	/**
	 * An unknown property is refused, naming the step, the property and the schema.
	 *
	 * @return void
	 */
	public function testAnUnknownPropertyIsRefusedByName(): void {
		try {
			$this->check()->refuseUnknownChangedFields(flowId: 'f1', graph: $this->graph(['omschrijving', 'beschrijving']));
		} catch (FlowLifecycleRefused $e) {
			$this->assertSame(FlowLifecycleRefused::REASON_UNKNOWN_CHANGED_FIELD, $e->getReason());
			$this->assertStringContainsString('"beschrijving"', $e->getMessage());
			$this->assertStringContainsString('Step "start"', $e->getMessage());
			$this->assertStringContainsString('schema "melding"', $e->getMessage());
			$this->assertStringNotContainsString('"omschrijving"', $e->getMessage());
			return;
		}

		$this->fail('Expected the publish to be refused.');
	}//end testAnUnknownPropertyIsRefusedByName()

	/**
	 * The schema is resolved inside the trigger's register, by slug.
	 *
	 * @return void
	 */
	public function testTheSchemaIsResolvedInsideItsRegister(): void {
		$this->expectException(FlowLifecycleRefused::class);
		$this->check()->refuseUnknownChangedFields(flowId: 'f1', graph: $this->graph(['omschrijving'], schema: 'aanvraag'));
	}//end testTheSchemaIsResolvedInsideItsRegister()

	/**
	 * An unresolvable register or schema, a non-watching trigger and other nodes are left alone.
	 *
	 * @return void
	 */
	public function testWhatCannotBeCheckedIsLeftAlone(): void {
		$check = $this->check();
		$check->refuseUnknownChangedFields(flowId: 'f1', graph: $this->graph(['nope'], register: 'elders'));
		$check->refuseUnknownChangedFields(flowId: 'f1', graph: $this->graph(['nope'], schema: 'onbekend'));
		$check->refuseUnknownChangedFields(
			flowId: 'f1',
			graph: ['nodes' => [
				['id' => 't', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'zaken', 'schema' => 'melding']],
				['id' => 'n', 'type' => 'openregister.noop', 'config' => ['changedFields' => ['nope']]],
			]]
		);
		$this->addToAssertionCount(3);
	}//end testWhatCannotBeCheckedIsLeftAlone()
}//end class
