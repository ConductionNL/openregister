<?php

/**
 * Unit tests for register-level actions a schema inherits.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use PHPUnit\Framework\TestCase;

class ObjectActionDefinitionsTest extends TestCase {

	/**
	 * A register declaring a one-step `publish` that would elevate.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setConfiguration(
			[
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'description' => 'Make it public.',
						'steps' => [['id' => 'one', 'type' => 'openregister.set-fields', 'config' => ['set' => ['a' => 1]]]],
						'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create']]],
					],
				],
			]
		);
		return $register;
	}//end register()

	/**
	 * A schema with the given configuration.
	 *
	 * @param array $configuration The configuration.
	 *
	 * @return Schema
	 */
	private function schema(array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setSlug('besluit');
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	public function testARegisterWidePublishReachesASchemaThatDeclaresNone(): void {
		$definitions = (new ObjectActionDefinitions($this->createMock(RegisterMapper::class)))
			->forSchema($this->schema(), $this->register());

		$this->assertArrayHasKey('publish', $definitions);
		$this->assertCount(1, $definitions['publish']->body);
	}//end testARegisterWidePublishReachesASchemaThatDeclaresNone()

	public function testAnInheritedActionNeverElevates(): void {
		$definitions = (new ObjectActionDefinitions($this->createMock(RegisterMapper::class)))
			->forSchema($this->schema(), $this->register());

		$this->assertSame([], $definitions['publish']->elevate);
	}//end testAnInheritedActionNeverElevates()

	public function testASchemaEntryReplacesTheInheritedOneWhole(): void {
		$schema = $this->schema(
			[
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'description' => 'Two steps.',
						'steps' => [['type' => 'openregister.set-fields'], ['type' => 'openregister.send-notification']],
					],
				],
			]
		);

		$definitions = (new ObjectActionDefinitions($this->createMock(RegisterMapper::class)))->forSchema($schema, $this->register());

		$this->assertCount(2, $definitions['publish']->body);
		$this->assertSame('Two steps.', $definitions['publish']->description);
	}//end testASchemaEntryReplacesTheInheritedOneWhole()

	public function testAMalformedSchemaEntryStillShadowsTheInheritedOne(): void {
		$schema = $this->schema(['x-openregister-action' => ['publish' => ['transition' => 'x', 'flow' => 'y']]]);

		$definitions = (new ObjectActionDefinitions($this->createMock(RegisterMapper::class)))->forSchema($schema, $this->register());

		$this->assertArrayNotHasKey('publish', $definitions);
	}//end testAMalformedSchemaEntryStillShadowsTheInheritedOne()

	public function testTheRegisterIsLoadedByIdAndAMissingOneInheritsNothing(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function ($id) {
				if ((string)$id === '3') {
					return $this->register();
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
			}
		);

		$definitions = new ObjectActionDefinitions($registers);

		$this->assertNotNull($definitions->find($this->schema(), 'publish', '3'));
		$this->assertNull($definitions->find($this->schema(), 'publish', '4'));
	}//end testTheRegisterIsLoadedByIdAndAMissingOneInheritsNothing()
}//end class
