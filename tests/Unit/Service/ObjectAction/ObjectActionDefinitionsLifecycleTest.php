<?php

/**
 * How a declared action resolves against a lifecycle value that is not a
 * map (read as no lifecycle), and what a register that cannot be read
 * contributes (nothing).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle tests for {@see ObjectActionDefinitions}.
 */
class ObjectActionDefinitionsLifecycleTest extends TestCase {

	/**
	 * A non-map lifecycle reads as none and admits the transition; a
	 * lifecycle without that transition drops it.
	 *
	 * @return void
	 */
	public function testANonMapLifecycleReadsAsNone(): void {
		$action = ['x-openregister-action' => ['close' => ['transition' => 'close']]];

		$this->assertSame(['close'], array_keys(ObjectActionDefinitions::resolve(schemaConfig: $action + ['x-openregister-lifecycle' => 'closed-flow'], registerConfig: [])));
		$this->assertSame([], array_keys(ObjectActionDefinitions::resolve(schemaConfig: $action + ['x-openregister-lifecycle' => ['transitions' => ['open' => []]]], registerConfig: [])));
	}//end testANonMapLifecycleReadsAsNone()

	/**
	 * A register reference that cannot be resolved contributes no inherited
	 * actions, while a resolvable one does.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterContributesNoActions(): void {
		$register = new Register();
		$register->setConfiguration(['x-openregister-action' => ['archive' => ['steps' => [['type' => 'log']]]]]);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->expects($this->exactly(2))->method('find')->willReturnCallback(
			static fn (int|string $id): Register => match ($id) {
				'cases' => $register,
				default => throw new DoesNotExistException('no register'),
			}
		);
		$definitions = new ObjectActionDefinitions(registers: $registers);

		$this->assertSame([], array_keys($definitions->forSchema(schema: new Schema(), register: 'gone')));
		$this->assertSame([], array_keys($definitions->forSchema(schema: new Schema(), register: '')));
		$this->assertSame([], array_keys($definitions->forSchema(schema: new Schema())));
		$this->assertSame(['archive'], array_keys($definitions->forSchema(schema: new Schema(), register: 'cases')));
	}//end testAnUnreadableRegisterContributesNoActions()
}//end class
