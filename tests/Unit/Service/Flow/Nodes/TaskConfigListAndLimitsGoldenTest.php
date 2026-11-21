<?php

/**
 * Characterisation tests for the task-config list reader and the portal-task
 * limit refusals.
 *
 * Pins `TaskConfigCommon::listOf()` for every input shape and the exact
 * refusal (or acceptance) `PortalTaskConfig::validate()` gives for each
 * numeric limit and for a file count below one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\TaskConfigCommon;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Golden tests for listOf() and the portal-task limit refusals.
 */
class TaskConfigListAndLimitsGoldenTest extends TestCase {

	/**
	 * Every input shape reads as the same list as before.
	 *
	 * @return void
	 */
	public function testListOfReadsEveryShape(): void {
		$common = new TaskConfigCommon(defaultOutcomeKey: 'outcome');

		$this->assertSame(['a', 'b'], $common->listOf(value: ' a, ,b ,'));
		$this->assertSame([], $common->listOf(value: ''));
		$this->assertSame(['x', '1', '2.5', '1'], $common->listOf(value: [' x ', '', 1, 2.5, true, null, ['n'], false]));
		$this->assertSame([], $common->listOf(value: 42));
		$this->assertSame([], $common->listOf(value: null));
		$this->assertSame([], $common->listOf(value: []));
	}//end testListOfReadsEveryShape()

	/**
	 * Each limit is refused or accepted exactly as before, in key order.
	 *
	 * @return void
	 */
	public function testPortalLimitsAreRefusedByName(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$config = new PortalTaskConfig(l10n: $l10n);

		$refused = [
			'files not a number'   => [['uploadMaxFiles' => 'many', 'heartbeatMinutes' => 'x'], 'uploadMaxFiles must be a number.'],
			'size not a number'    => [['uploadMaxSizeMb' => 'big', 'heartbeatMinutes' => 'x'], 'uploadMaxSizeMb must be a number.'],
			'heartbeat not number' => [['heartbeatMinutes' => [5]], 'heartbeatMinutes must be a number.'],
			'zero files'           => [['uploadMaxFiles' => '0'], 'uploadMaxFiles must be at least 1; turn "Upload required" off to allow a completion without a file.'],
			'negative files'       => [['uploadMaxFiles' => -2.5], 'uploadMaxFiles must be at least 1; turn "Upload required" off to allow a completion without a file.'],
			'fraction below one'   => [['uploadMaxFiles' => '0.9'], 'uploadMaxFiles must be at least 1; turn "Upload required" off to allow a completion without a file.'],
		];
		foreach ($refused as $label => [$overrides, $message]) {
			try {
				$config->validate(config: ['title' => 'Send it'] + $overrides);
				$this->fail($label . ' was accepted');
			} catch (UnexpectedValueException $e) {
				$this->assertSame($message, $e->getMessage(), $label);
			}
		}

		$config->validate(config: ['title' => 'Send it', 'uploadMaxFiles' => '1', 'uploadMaxSizeMb' => '', 'heartbeatMinutes' => null]);
		$config->validate(config: ['title' => 'Send it', 'uploadMaxFiles' => null, 'uploadMaxSizeMb' => 0.5, 'heartbeatMinutes' => 15]);
		$this->addToAssertionCount(1);
	}//end testPortalLimitsAreRefusedByName()
}//end class
