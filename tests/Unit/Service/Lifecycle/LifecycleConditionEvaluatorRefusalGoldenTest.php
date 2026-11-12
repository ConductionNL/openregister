<?php

/**
 * Golden outcomes of LifecycleConditionEvaluator::refusal(): when a
 * transition condition refuses, what the refusal says, and what is logged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionReporting;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins allowed (null) and refused (error array) outcomes of refusal().
 */
class LifecycleConditionEvaluatorRefusalGoldenTest extends TestCase {

	private const HOLDS_WHEN_REASONED = ['!!' => ['var' => 'object.reason']];

	private LoggerInterface&MockObject $logger;

	private string $language = 'nl';

	private function evaluator(): LifecycleConditionEvaluator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturnCallback(fn (): string => $this->language);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => 'T:' . vsprintf($text, $parameters)
		);

		return new LifecycleConditionEvaluator(new LifecycleConditionReporting($l10n, $this->logger), new \OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCheck(new ConditionDialect(
				ast: new CalculationEvaluator(
					placeholders: new PlaceholderResolver(userSession: $this->createMock(IUserSession::class))
				)
			), new \OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCaller($this->createMock(IUserSession::class), $this->createMock(IGroupManager::class))));
	}//end evaluator()

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $object
	 *
	 * @return array<string, string>|null
	 */
	private function refusal(array $spec, array $object = []): ?array {
		return $this->evaluator()->refusal(
			spec: $spec,
			newData: $object,
			oldData: [],
			action: 'decide',
			from: 'open',
			to: 'closed',
			schemaSlug: 'case',
			field: 'status'
		);
	}//end refusal()

	private function fallback(): string {
		return 'T:The conditions for "decide" are not met, so "status" cannot change yet.';
	}//end fallback()

	/**
	 * Specs that never refuse.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function admitted(): array {
		return [
			'no condition key' => [['message' => 'x'], []],
			'null condition' => [['condition' => null], []],
			'switched off' => [['condition' => 'scalar', 'enabled' => false], []],
			'holds' => [['condition' => self::HOLDS_WHEN_REASONED], ['reason' => 'yes']],
			'enabled true and holds' => [['condition' => self::HOLDS_WHEN_REASONED, 'enabled' => true], ['reason' => 'yes']],
		];
	}//end admitted()

	/**
	 * @dataProvider admitted
	 *
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $object
	 */
	public function testAdmitted(array $spec, array $object): void {
		$this->logger->expects($this->never())->method('debug');
		$this->logger->expects($this->never())->method('warning');

		$this->assertNull($this->refusal($spec, $object));
	}//end testAdmitted()

	public function testAWellFormedRuleThatFailsRefusesAndLogsDebug(): void {
		$this->logger->expects($this->once())->method('debug')->with(
			'[LifecycleConditionEvaluator] Transition condition did not hold.',
			['schema' => 'case', 'action' => 'decide', 'field' => 'status']
		);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(
			['code' => 'lifecycle-condition-unmet', 'field' => 'status', 'action' => 'decide', 'message' => $this->fallback()],
			$this->refusal(['condition' => self::HOLDS_WHEN_REASONED])
		);
	}//end testAWellFormedRuleThatFailsRefusesAndLogsDebug()

	/**
	 * Malformed conditions: refused, warned, never debug-logged.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformed(): array {
		return [
			'scalar' => ['always'],
			'true' => [true],
			'empty array' => [[]],
			'disabled flag not strictly false' => [0],
		];
	}//end malformed()

	/**
	 * @dataProvider malformed
	 */
	public function testAMalformedConditionRefusesWithAWarningOnly(mixed $condition): void {
		$this->logger->expects($this->once())->method('warning');
		$this->logger->expects($this->never())->method('debug');

		$refusal = $this->refusal(['condition' => $condition, 'enabled' => 0]);

		$this->assertSame('lifecycle-condition-unmet', $refusal['code']);
		$this->assertSame($this->fallback(), $refusal['message']);
	}//end testAMalformedConditionRefusesWithAWarningOnly()

	/**
	 * Declared messages and the message the refusal carries.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: string}>
	 */
	public static function messages(): array {
		return [
			'string' => ['Give a reason.', 'nl', 'Give a reason.'],
			'empty string falls back' => ['', 'nl', 'FALLBACK'],
			'number falls back' => [12, 'nl', 'FALLBACK'],
			'caller language' => [['nl' => 'Geef reden.', 'en' => 'Give reason.', 'defaultLocale' => 'en'], 'nl', 'Geef reden.'],
			'default locale next' => [['de' => 'Grund.', 'en' => 'Give reason.', 'defaultLocale' => 'de'], 'fr', 'Grund.'],
			'english next' => [['de' => 'Grund.', 'en' => 'Give reason.', 'defaultLocale' => 'it'], 'fr', 'Give reason.'],
			'empty caller entry skipped' => [['nl' => '', 'en' => 'Give reason.'], 'nl', 'Give reason.'],
			'non-string caller entry skipped' => [['nl' => ['x'], 'en' => 'Give reason.'], 'nl', 'Give reason.'],
			'first usable entry' => [['de' => '', 'fr' => 7, 'es' => 'Razón.', 'it' => 'Motivo.'], 'nl', 'Razón.'],
			'default locale is not text' => [['defaultLocale' => 'fr'], 'nl', 'FALLBACK'],
			'non-string default locale' => [['defaultLocale' => ['fr'], 'fr' => 'Raison.'], 'nl', 'Raison.'],
			'empty map falls back' => [[], 'nl', 'FALLBACK'],
			'nothing usable falls back' => [['nl' => '', 'en' => null], 'nl', 'FALLBACK'],
		];
	}//end messages()

	/**
	 * @dataProvider messages
	 */
	public function testTheRefusalMessage(mixed $declared, string $language, string $expected): void {
		$this->language = $language;
		if ($expected === 'FALLBACK') {
			$expected = $this->fallback();
		}

		$refusal = $this->refusal(['condition' => self::HOLDS_WHEN_REASONED, 'message' => $declared]);

		$this->assertSame($expected, $refusal['message']);
	}//end testTheRefusalMessage()
}//end class
