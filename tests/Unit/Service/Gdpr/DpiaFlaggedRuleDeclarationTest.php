<?php

/**
 * Pins the declared `dpiaFlagged` notification rule on `dataSubjectRequest`.
 *
 * Detection and manual flagging share one declared dispatch path: the rule
 * fires on the `dpiaRequired` false-to-true boundary of any update, and the
 * detection job holds no notification dependency of its own (no imperative
 * dispatch). The trigger is run through the real {@see NotificationTriggerMatcher}
 * so a declaration that reads right but never matches still fails here.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Gdpr;

use OCA\OpenRegister\BackgroundJob\Support\DsarDpiaDetectionServices;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerMatcher;
use OCA\OpenRegister\Service\Gdpr\PrivacyOfficerRecipientResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Declaration and boundary behaviour of the `dpiaFlagged` rule.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/tasks.md#task-3.4
 */
final class DpiaFlaggedRuleDeclarationTest extends TestCase {

	/**
	 * The register file the rule ships in.
	 */
	private const REGISTER_FILE = __DIR__ . '/../../../../lib/Settings/data_subject_request_register.json';

	/**
	 * Read the `dataSubjectRequest` schema from the shipped register.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$json = file_get_contents(self::REGISTER_FILE);
		self::assertIsString($json);
		$register = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		return $register['components']['schemas']['dataSubjectRequest'];
	}//end schema()

	/**
	 * The declared rule.
	 *
	 * @return array<string, mixed>
	 */
	private static function rule(): array {
		$rules = (self::schema()['x-openregister-notifications'] ?? []);
		self::assertArrayHasKey('dpiaFlagged', $rules);

		return $rules['dpiaFlagged'];
	}//end rule()

	/**
	 * Whether the declared trigger fires for one update of `dpiaRequired`.
	 *
	 * @param array<string, mixed> $oldData The case data before the write.
	 * @param array<string, mixed> $newData The case data after the write.
	 * @param string               $event   The dispatched trigger type.
	 *
	 * @return bool
	 */
	private static function fires(array $oldData, array $newData, string $event = 'calculatedChange'): bool {
		$matcher = new NotificationTriggerMatcher(trigger: $event, context: ['_oldData' => $oldData, '_newData' => $newData]);

		return $matcher->matches(triggerSpec: self::rule()['trigger']);
	}//end fires()

	/**
	 * The rule watches the declared boolean `dpiaRequired` on the false-to-true boundary.
	 *
	 * @return void
	 */
	public function testRuleWatchesTheDpiaRequiredBoundary(): void {
		$rule = self::rule();
		self::assertTrue($rule['enabled']);
		self::assertSame('calculatedChange', $rule['trigger']['type']);
		self::assertSame('dpiaRequired', $rule['trigger']['field']);
		self::assertSame(['eq' => true], $rule['trigger']['condition']);
		self::assertSame(['ne' => true], $rule['trigger']['previously']);
		self::assertSame('boolean', self::schema()['properties']['dpiaRequired']['type']);
	}//end testRuleWatchesTheDpiaRequiredBoundary()

	/**
	 * The recipient is the pack-resolved privacy officer, delivered as an in-app notification.
	 *
	 * @return void
	 */
	public function testRecipientIsThePackResolvedPrivacyOfficer(): void {
		$rule = self::rule();
		self::assertSame(
			[['kind' => 'expression', 'resolver' => PrivacyOfficerRecipientResolver::class]],
			$rule['recipients']
		);
		self::assertTrue(class_exists(PrivacyOfficerRecipientResolver::class));
		self::assertSame(['nc-notification'], $rule['channels']);
		self::assertNotSame('', $rule['subject']['nl']);
		self::assertNotSame('', $rule['subject']['en']);
		self::assertNotSame('', $rule['message']['nl']);
		self::assertNotSame('', $rule['message']['en']);
	}//end testRecipientIsThePackResolvedPrivacyOfficer()

	/**
	 * A flag set by the job or by a handler crosses the boundary once; anything else stays quiet.
	 *
	 * @return void
	 */
	public function testFiresOnlyWhenTheFlagIsRaised(): void {
		self::assertTrue(self::fires(oldData: ['dpiaRequired' => false], newData: ['dpiaRequired' => true]));
		self::assertTrue(self::fires(oldData: [], newData: ['dpiaRequired' => true]), 'An unset flag counts as not raised');
		self::assertFalse(self::fires(oldData: ['dpiaRequired' => true], newData: ['dpiaRequired' => true]), 'A rewrite of a flagged case must not re-notify');
		self::assertFalse(self::fires(oldData: ['dpiaRequired' => true], newData: ['dpiaRequired' => false]), 'Clearing the flag must not notify');
		self::assertFalse(self::fires(oldData: ['dpiaRequired' => false], newData: ['dpiaRequired' => false]));
		self::assertFalse(self::fires(oldData: ['dpiaRequired' => false], newData: ['dpiaRequired' => true], event: 'created'));
	}//end testFiresOnlyWhenTheFlagIsRaised()

	/**
	 * The detection job reaches the officer only through the declared rule.
	 *
	 * @return void
	 */
	public function testDetectionJobHoldsNoNotificationDependency(): void {
		$constructor = (new ReflectionClass(DsarDpiaDetectionServices::class))->getConstructor();
		self::assertNotNull($constructor);
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : (string) $type;
			self::assertStringNotContainsString('Notification', $name, $parameter->getName() . ' would dispatch imperatively');
			self::assertStringNotContainsString('Notifier', $name, $parameter->getName() . ' would dispatch imperatively');
		}
	}//end testDetectionJobHoldsNoNotificationDependency()
}//end class
