<?php

/**
 * Golden test for the audit entry and log line RuleAuditService::switched() writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Rules\RuleAuditService;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleOrigin;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The switch entry and its log line, pinned for a signed-in actor and for the system.
 */
class RuleAuditServiceGoldenTest extends TestCase {

	/**
	 * Switch a rule and return the entry and every log call.
	 *
	 * @param IUser|null $user    The signed-in user.
	 * @param bool       $enabled What the rule is switched to.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, array{0: string, 1: array<string, mixed>}>, 2: string} The entry, the log calls and the rule id.
	 */
	private function switchRule(?IUser $user, bool $enabled): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$logs): void {
				unset($context['line']);
				$logs[] = [$message, $context];
			}
		);
		$rule = new RuleDescriptor(
			origin: new RuleOrigin(kind: RuleVocabulary::KIND_CALCULATION, schemaSlug: 'bezwaar', key: 'termijn', source: 'x'),
			label: 'Termijn',
			enabled: !$enabled
		);

		$entry = (new RuleAuditService(userSession: $session, logger: $logger))->switched(rule: $rule, enabled: $enabled);

		return [$entry, $logs, $rule->getId()];
	}//end switchRule()

	public function testASignedInActorSwitchingARuleOff(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$user->method('getDisplayName')->willReturn('Anna de Vries');

		[$entry, $logs, $ruleId] = $this->switchRule(user: $user, enabled: false);

		$expected = ['actor' => 'anna', 'actorName' => 'Anna de Vries', 'ruleId' => $ruleId, 'from' => true, 'to' => false];
		$this->assertSame($expected, $entry);
		$this->assertSame(
			[
				[
					'[openregister_rule_switch] Anna de Vries switched rule "' . $ruleId . '" on schema "bezwaar" from enabled to disabled.',
					[
						'eventType' => RuleAuditService::EVENT_TYPE,
						'file' => (new \ReflectionClass(RuleAuditService::class))->getFileName(),
						'rule' => $expected,
						'schema' => 'bezwaar',
						'kind' => RuleVocabulary::KIND_CALCULATION,
					],
				],
			],
			$logs
		);
	}//end testASignedInActorSwitchingARuleOff()

	public function testTheSystemSwitchingARuleOn(): void {
		[$entry, $logs, $ruleId] = $this->switchRule(user: null, enabled: true);

		$this->assertSame(['actor' => 'system', 'actorName' => 'System', 'ruleId' => $ruleId, 'from' => false, 'to' => true], $entry);
		$this->assertSame('[openregister_rule_switch] System switched rule "' . $ruleId . '" on schema "bezwaar" from disabled to enabled.', $logs[0][0]);
	}//end testTheSystemSwitchingARuleOn()
}//end class
