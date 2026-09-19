<?php

/**
 * The switch that decides whether a deny refuses anything yet.
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

use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The three states of the deny rollout, and the default an instance lands in.
 */
class DenyEnforcementModeTest extends TestCase {

	/**
	 * A switch reading one configured value.
	 *
	 * @param string               $configured The stored value.
	 * @param LoggerInterface|null $logger     Where a record lands.
	 *
	 * @return DenyEnforcementMode The switch under test.
	 */
	private function modeReading(string $configured, ?LoggerInterface $logger = null): DenyEnforcementMode {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($configured);

		return new DenyEnforcementMode($appConfig, ($logger ?? new NullLogger()));
	}//end modeReading()

	/**
	 * 🔴 An instance that has never set the key runs in staging.
	 *
	 * This is decision D15 in one assertion. If this flips to `enforcing`, the
	 * deny ships enforcing and the whole staging design is decoration.
	 *
	 * @return void
	 */
	public function testTheDefaultIsStagingAndNotEnforcing(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$mode = new DenyEnforcementMode($appConfig, new NullLogger());

		$this->assertSame(DenyEnforcementMode::MODE_STAGING, $mode->current());
		$this->assertFalse($mode->enforces());
		$this->assertTrue($mode->resolves());
	}//end testTheDefaultIsStagingAndNotEnforcing()

	/**
	 * Enforcing both resolves and applies.
	 *
	 * @return void
	 */
	public function testEnforcingResolvesAndEnforces(): void {
		$mode = $this->modeReading(DenyEnforcementMode::MODE_ENFORCING);

		$this->assertTrue($mode->enforces());
		$this->assertTrue($mode->resolves());
	}//end testEnforcingResolvesAndEnforces()

	/**
	 * Off resolves nothing, which is why it also records nothing.
	 *
	 * @return void
	 */
	public function testOffNeitherResolvesNorEnforces(): void {
		$mode = $this->modeReading(DenyEnforcementMode::MODE_OFF);

		$this->assertFalse($mode->enforces());
		$this->assertFalse($mode->resolves());
	}//end testOffNeitherResolvesNorEnforces()

	/**
	 * A value nobody declared reads as staging, not as enforcing.
	 *
	 * A typo in an admin's `occ config:app:set` must never be the thing that
	 * starts refusing people. Staging is the fail-safe reading in both
	 * directions: it refuses nobody, and it keeps recording.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedValueFallsBackToStaging(): void {
		foreach (['ENFORCE', 'on', 'true', '1', ''] as $written) {
			$mode = $this->modeReading($written);
			$this->assertSame(
				DenyEnforcementMode::MODE_STAGING,
				$mode->current(),
				sprintf('The value "%s" did not fall back to staging.', $written)
			);
		}
	}//end testAnUnrecognisedValueFallsBackToStaging()

	/**
	 * Case and surrounding space do not change the mode.
	 *
	 * @return void
	 */
	public function testTheValueIsReadCaseInsensitivelyAndTrimmed(): void {
		$this->assertTrue($this->modeReading('  Enforcing ')->enforces());
		$this->assertFalse($this->modeReading('OFF')->resolves());
	}//end testTheValueIsReadCaseInsensitivelyAndTrimmed()

	/**
	 * An unreadable config store answers staging rather than throwing.
	 *
	 * @return void
	 */
	public function testAnUnreadableConfigStoreAnswersStaging(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new \RuntimeException('no config'));
		$mode = new DenyEnforcementMode($appConfig, new NullLogger());

		$this->assertSame(DenyEnforcementMode::MODE_STAGING, $mode->current());
		$this->assertFalse($mode->enforces());
	}//end testAnUnreadableConfigStoreAnswersStaging()

	/**
	 * The record carries the rule, so the log answers "what will change".
	 *
	 * Warning level rather than info on purpose: an administrator reading for
	 * what the switch will do should not have to raise the level to see it.
	 *
	 * @return void
	 */
	public function testTheRecordNamesTheRuleAndThePrincipalAtWarningLevel(): void {
		$records = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []) use (&$records): void {
				$records[] = ['message' => (string)$message, 'context' => $context];
			}
		);

		$this->modeReading(DenyEnforcementMode::MODE_STAGING, $logger)->record(
			denial: ['rule' => 'waarnemers', 'principal' => 'waarnemers', 'action' => 'read', 'conditional' => false],
			action: 'read',
			userId: 'ana',
			context: ['schemaId' => 7, 'path' => 'object']
		);

		$this->assertCount(1, $records);
		$this->assertSame('waarnemers', $records[0]['context']['rule']);
		$this->assertSame('waarnemers', $records[0]['context']['principal']);
		$this->assertSame('read', $records[0]['context']['action']);
		$this->assertSame('ana', $records[0]['context']['userId']);
		$this->assertSame(7, $records[0]['context']['schemaId']);
		$this->assertSame('object', $records[0]['context']['path']);
		$this->assertSame(DenyEnforcementMode::MODE_STAGING, $records[0]['context']['mode']);
	}//end testTheRecordNamesTheRuleAndThePrincipalAtWarningLevel()
}//end class
