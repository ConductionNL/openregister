<?php

/**
 * Unit tests for ObjectActionWriteElevation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\WriteElevationInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\ObjectAction\ActionElevation;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionWriteElevation;
use PHPUnit\Framework\TestCase;

/**
 * The permission check's elevation port is answered from the action run's open frame.
 */
final class ObjectActionWriteElevationTest extends TestCase {

	/**
	 * A refused write to schema 9 in a register.
	 *
	 * @param string $verb     The verb.
	 * @param string $register The register written to.
	 *
	 * @return PermissionAsk The question.
	 */
	private function ask(string $verb, string $register): PermissionAsk {
		$schema = new Schema();
		$schema->setId(9);
		$ask = new PermissionAsk(schema: $schema, action: $verb);
		$ask->register = $register;
		return $ask;
	}//end ask()

	/**
	 * The adapter is the port.
	 *
	 * @return void
	 */
	public function testItAnswersTheCorePort(): void {
		self::assertInstanceOf(WriteElevationInterface::class, new ObjectActionWriteElevation());
	}//end testItAnswersTheCorePort()

	/**
	 * Inside a run whose elevation lists the write it is permitted; an unlisted verb or register is not.
	 *
	 * @return void
	 */
	public function testAListedWriteIsPermittedInsideTheRunOnly(): void {
		$elevation = new ObjectActionWriteElevation();
		$entries   = [['register' => '4', 'schema' => '9', 'verbs' => ['create']]];

		[$listed, $otherVerb, $otherRegister] = ObjectActionElevationScope::run(
			new ActionElevation('publish', 'anna', $entries),
			fn (): array => [
				$elevation->permits(ask: $this->ask(verb: 'create', register: '4')),
				$elevation->permits(ask: $this->ask(verb: 'delete', register: '4')),
				$elevation->permits(ask: $this->ask(verb: 'create', register: '5')),
			]
		);

		self::assertTrue($listed);
		self::assertFalse($otherVerb);
		self::assertFalse($otherRegister);
		self::assertFalse($elevation->permits(ask: $this->ask(verb: 'create', register: '4')), 'no run, no elevation');
	}//end testAListedWriteIsPermittedInsideTheRunOnly()

	/**
	 * Without the port the permission check elevates nothing: the bundle's default is absent.
	 *
	 * @return void
	 */
	public function testThePermissionRulesHaveNoElevationByDefault(): void {
		self::assertNull((new PermissionRuleServices(conditionMatcher: $this->createMock(ConditionMatcher::class)))->writeElevation);
	}//end testThePermissionRulesHaveNoElevationByDefault()
}//end class
