<?php

/**
 * Golden outcomes of UnknownRuleKeyFindings for a register's block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings;
use PHPUnit\Framework\TestCase;

/**
 * A register save is refused for a condition on a malformed key, and admitted otherwise.
 */
class UnknownRuleKeyFindingsRegisterGoldenTest extends TestCase {

	/**
	 * A register whose read rule matches on one key.
	 *
	 * @param string $key The `match` key.
	 *
	 * @return Register The register.
	 */
	private function register(string $key): Register {
		$register = new Register();
		$register->setAuthorization(['read' => [['group' => 'staff', 'match' => [$key => 'x']]]]);

		return $register;
	}//end register()

	/**
	 * A malformed key is one finding; a well-formed key and record metadata are none.
	 *
	 * @return void
	 */
	public function testFindingsOfARegisterBlock(): void {
		$keys = new UnknownRuleKeyFindings();

		$this->assertSame(
			['The read rule for group "staff" in register "r" has a condition on "bad key", which names no property or record metadata, so it admits no row.'],
			$keys->registerFindings(authorization: $this->register(key: 'bad key')->getAuthorization(), context: 'register "r"')
		);
		$this->assertSame([], $keys->registerFindings(authorization: $this->register(key: 'status')->getAuthorization(), context: 'register "r"'));
		$this->assertSame([], $keys->registerFindings(authorization: $this->register(key: '_organisation')->getAuthorization(), context: 'register "r"'));
		$this->assertSame([], $keys->registerFindings(authorization: null, context: 'register "r"'));
	}//end testFindingsOfARegisterBlock()

	/**
	 * The save of a block with a malformed key is refused; with nothing stored there is nothing to excuse it.
	 *
	 * @return void
	 */
	public function testASaveWithAMalformedKeyIsRefused(): void {
		$this->expectException(AuthorizationBlockException::class);

		(new UnknownRuleKeyFindings())->assertRegisterSavable(register: $this->register(key: 'bad key'), subject: 'register "r"');
	}//end testASaveWithAMalformedKeyIsRefused()

	/**
	 * The save of a block with well-formed keys is admitted.
	 *
	 * @return void
	 */
	public function testASaveWithWellFormedKeysIsAdmitted(): void {
		(new UnknownRuleKeyFindings())->assertRegisterSavable(register: $this->register(key: 'status'), subject: 'register "r"');

		$this->addToAssertionCount(1);
	}//end testASaveWithWellFormedKeysIsAdmitted()
}//end class
