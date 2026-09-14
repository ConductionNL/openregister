<?php

/**
 * The save-time refusals are not staged, in any mode.
 *
 * The deny ships staged (D15): below `enforcing` a deny is resolved, recorded
 * and then ignored, so nobody is refused on the day a rule is written. That
 * staging covers EFFECTS on a caller. It deliberately does not cover the two
 * contradictions a block can carry, because those are mistakes in the rules
 * rather than effects on anybody: a principal granted and denied one verb at one
 * level, and a deny that leaves a register with no administrator.
 *
 * Writing such a block and finding out a month later, at the moment the switch
 * is flipped, is the outcome staging exists to prevent. So this file pins the
 * refusals against the mode, all three of them, through the method the register
 * save actually calls.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Task 9.7: a contradiction is refused whatever the enforcement mode says.
 *
 * @covers \OCA\OpenRegister\Db\RegisterMapper
 * @covers \OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator
 */
class SaveTimeRefusalsInEveryModeTest extends TestCase {

	/**
	 * Every mode an instance can be in, including the one nobody set.
	 *
	 * @return array<string, array{0: string}> The modes.
	 */
	public static function modes(): array {
		return [
			'off' => [DenyEnforcementMode::MODE_OFF],
			'staging' => [DenyEnforcementMode::MODE_STAGING],
			'enforcing' => [DenyEnforcementMode::MODE_ENFORCING],
			'a value nobody declared' => ['somethingelse'],
		];
	}//end modes()

	/**
	 * The register save's validation step, without a database behind it.
	 *
	 * The method the register save calls is exercised rather than the validator
	 * alone: a future change that gated the refusal on the mode would gate it
	 * HERE, and a test that only called the validator would stay green through
	 * it.
	 *
	 * @param Register $register The register being saved.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When the block contradicts itself.
	 */
	private function validateAsTheSaveDoes(Register $register): void {
		$reflection = new ReflectionClass(RegisterMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();

		// A dispatcher that declares nothing, so the catalogue answers the
		// canonical verbs alone. The mapper is built without its constructor
		// because everything else it holds is a database, and none of it is
		// reached before the block is refused.
		if ($reflection->hasProperty('eventDispatcher') === true) {
			$property = $reflection->getProperty('eventDispatcher');
			$property->setAccessible(true);
			$property->setValue($mapper, $this->createMock(originalClassName: IEventDispatcher::class));
		}

		$method = $reflection->getMethod('validateAuthorizationDeny');
		$method->setAccessible(true);
		$method->invoke($mapper, $register);
	}//end validateAsTheSaveDoes()

	/**
	 * A register carrying one block.
	 *
	 * @param array $authorization The block as written.
	 *
	 * @return Register The register.
	 */
	private function registerWith(array $authorization): Register {
		$register = new Register();
		$register->setId(1);
		$register->setTitle('Zaken');
		$register->setSlug('zaken');
		$register->setAuthorization($authorization);

		return $register;
	}//end registerWith()

	/**
	 * The mode reader an instance in this state answers with.
	 *
	 * The control for every case below: without it, a fixture that silently
	 * failed to set the mode would let all four cases pass for the wrong reason.
	 *
	 * @param string $stored The value stored in the app config.
	 *
	 * @return DenyEnforcementMode The reader.
	 */
	private function modeReading(string $stored): DenyEnforcementMode {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($stored);

		return new DenyEnforcementMode(appConfig: $appConfig, logger: new NullLogger());
	}//end modeReading()

	/**
	 * 🔴 A grant and a deny on one principal at one level is refused in every mode.
	 *
	 * @param string $stored The mode the instance is in.
	 *
	 * @return void
	 *
	 * @dataProvider modes
	 */
	public function testACollisionIsRefusedWhateverTheMode(string $stored): void {
		$mode = $this->modeReading(stored: $stored);
		$this->assertContains($mode->current(), DenyEnforcementMode::MODES);

		$register = $this->registerWith(
			[
				'delete' => ['behandelaars'],
				'manage' => ['beheerders'],
				'deny' => ['delete' => ['behandelaars']],
			]
		);

		try {
			$this->validateAsTheSaveDoes(register: $register);
			$this->fail(sprintf('The collision was stored while the instance was "%s".', $mode->current()));
		} catch (AuthorizationBlockException $e) {
			$this->assertSame(422, $e->getHttpStatusCode());
			$this->assertStringContainsString('behandelaars', $e->getMessage());
			$this->assertStringContainsString('delete', $e->getMessage());
		}
	}//end testACollisionIsRefusedWhateverTheMode()

	/**
	 * 🔴 An orphaned `manage` is refused in every mode.
	 *
	 * @param string $stored The mode the instance is in.
	 *
	 * @return void
	 *
	 * @dataProvider modes
	 */
	public function testAnOrphanedManageIsRefusedWhateverTheMode(string $stored): void {
		$mode = $this->modeReading(stored: $stored);
		$this->assertContains($mode->current(), DenyEnforcementMode::MODES);

		$register = $this->registerWith(
			[
				'manage' => ['beheerders'],
				'deny' => ['manage' => ['beheerders']],
			]
		);

		try {
			$this->validateAsTheSaveDoes(register: $register);
			$this->fail(sprintf('Administration was denied away while the instance was "%s".', $mode->current()));
		} catch (AuthorizationBlockException $e) {
			$this->assertSame(422, $e->getHttpStatusCode());
			$this->assertStringContainsString('beheerders', $e->getMessage());
			$this->assertStringContainsString('zaken', $e->getMessage());
		}
	}//end testAnOrphanedManageIsRefusedWhateverTheMode()

	/**
	 * A block with a deny and no contradiction still saves, in every mode.
	 *
	 * The control for both cases above: without it, a validator that refused
	 * every block carrying a deny would pass them.
	 *
	 * @param string $stored The mode the instance is in.
	 *
	 * @return void
	 *
	 * @dataProvider modes
	 */
	public function testAnHonestDenyIsStoredWhateverTheMode(string $stored): void {
		$this->assertContains($this->modeReading(stored: $stored)->current(), DenyEnforcementMode::MODES);

		$register = $this->registerWith(
			[
				'read' => ['iedereen'],
				'manage' => ['beheerders'],
				'deny' => ['read' => ['stagiairs']],
			]
		);

		$this->validateAsTheSaveDoes(register: $register);

		$this->assertTrue(true, 'A block whose deny contradicts nothing was stored.');
	}//end testAnHonestDenyIsStoredWhateverTheMode()
}//end class
