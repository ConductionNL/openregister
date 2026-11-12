<?php

/**
 * One escalation rule turned into a rung: the derived key, the defaults and
 * every refusal of a malformed trigger, offset, unit, priority or role.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\EscalationRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EscalationRule::toRung().
 */
class EscalationRuleBehaviourTest extends TestCase {

	private const VALID = ['trigger' => 'preBreach', 'offset' => 4, 'offsetUnit' => 'hours'];

	public function testAMinimalRuleDerivesItsKeyAndTakesTheDefaults(): void {
		$rung = (new EscalationRule(self::VALID, 0))->toRung();

		$this->assertSame(
			[
				'key' => 'preBreach:4:hours',
				'trigger' => 'preBreach',
				'offset' => 4,
				'offsetUnit' => 'hours',
				'notifyRole' => [],
				'escalateToRole' => [],
				'priority' => 'medium',
				'message' => null,
				'consequence' => null,
				'openIncident' => false,
			],
			$rung
		);
	}//end testAMinimalRuleDerivesItsKeyAndTakesTheDefaults()

	public function testAFullRuleKeepsItsKeyTrimsTextsAndDeduplicatesRoles(): void {
		$rule = [
			'key' => '  first-warning ',
			'trigger' => 'slaBreached',
			'offset' => 0,
			'offsetUnit' => 'businessDays',
			'priority' => 'critical',
			'notifyRole' => ' performer ',
			'escalateToRole' => ['manager', ' manager', 'director'],
			'message' => '  Late  ',
			'consequence' => '   ',
			'openIncident' => true,
		];

		$rung = (new EscalationRule($rule, 2))->toRung();

		$this->assertSame('first-warning', $rung['key']);
		$this->assertSame('slaBreached', $rung['trigger']);
		$this->assertSame(0, $rung['offset']);
		$this->assertSame('businessDays', $rung['offsetUnit']);
		$this->assertSame('critical', $rung['priority']);
		$this->assertSame(['performer'], $rung['notifyRole']);
		$this->assertSame(['manager', 'director'], $rung['escalateToRole']);
		$this->assertSame('Late', $rung['message']);
		$this->assertNull($rung['consequence']);
		$this->assertTrue($rung['openIncident']);
	}//end testAFullRuleKeepsItsKeyTrimsTextsAndDeduplicatesRoles()

	public function testABlankKeyFallsBackToTheDerivedKey(): void {
		$rung = (new EscalationRule(['key' => '  '] + self::VALID + [], 0))->toRung();

		$this->assertSame('preBreach:4:hours', $rung['key']);
	}//end testABlankKeyFallsBackToTheDerivedKey()

	public function testAnOpenIncidentThatIsNotStrictlyTrueStaysOff(): void {
		$rung = (new EscalationRule(self::VALID + ['openIncident' => 'yes', 'message' => 42], 0))->toRung();

		$this->assertFalse($rung['openIncident']);
		$this->assertNull($rung['message']);
	}//end testAnOpenIncidentThatIsNotStrictlyTrueStaysOff()

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function malformedRules(): array {
		return [
			'missing trigger' => [['trigger' => null], "Escalation rule #3 has trigger ''"],
			'unknown trigger' => [['trigger' => 'whenever'], "Escalation rule #3 has trigger 'whenever'"],
			'missing offset' => [['offset' => null], "Escalation rule #3 has offset 'NULL'"],
			'string offset' => [['offset' => '4'], "has offset ''4''"],
			'negative offset' => [['offset' => -1], "has offset '-1'"],
			'non-string unit' => [['offsetUnit' => 3], "has offsetUnit '3'"],
			'unknown unit' => [['offsetUnit' => 'weeks'], "has offsetUnit ''weeks''"],
			'unknown priority' => [['priority' => 'urgent'], "has priority 'urgent'"],
			'role list is a number' => [['notifyRole' => 5], 'notifyRole must be a role name or a list of role names'],
			'empty role in list' => [['escalateToRole' => ['manager', ' ']], 'escalateToRole contains an empty or non-string role'],
			'non-string role in list' => [['notifyRole' => [7]], 'notifyRole contains an empty or non-string role'],
		];
	}//end malformedRules()

	/**
	 * @param array<string, mixed> $override The field that breaks the rule.
	 * @param string               $message  A fragment the refusal names.
	 */
	#[DataProvider('malformedRules')]
	public function testAMalformedRuleIsRefusedNamingItsIndexAndField(array $override, string $message): void {
		$rule = array_merge(self::VALID, $override);

		try {
			(new EscalationRule($rule, 3))->toRung();
			$this->fail('A malformed rule was accepted.');
		} catch (FlowTimerValidationException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
			$this->assertStringContainsString('#3', $e->getMessage());
		}
	}//end testAMalformedRuleIsRefusedNamingItsIndexAndField()
}//end class
