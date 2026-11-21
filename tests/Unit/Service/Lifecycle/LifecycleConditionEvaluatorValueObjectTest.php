<?php

/**
 * LifecycleConditionEvaluator's value-object methods answer exactly as the
 * scalar signatures they sit beside, and the document names the caller.
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
use OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCaller;
use OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCheck;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWrite;
use OCA\OpenRegister\Service\Lifecycle\TransitionAttempt;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins refusalOf()/documentOf() against refusal()/document().
 */
class LifecycleConditionEvaluatorValueObjectTest extends TestCase {

	private function evaluator(?IUser $user): LifecycleConditionEvaluator {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['beslissers']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('nl');
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => 'T:' . vsprintf($text, $parameters)
		);
		$dialect = new ConditionDialect(
			ast: new CalculationEvaluator(placeholders: new PlaceholderResolver(userSession: $session))
		);

		return new LifecycleConditionEvaluator(
			new LifecycleConditionReporting($l10n, $this->createMock(LoggerInterface::class)),
			new LifecycleRuleCheck($dialect, new LifecycleRuleCaller($session, $groups))
		);
	}//end evaluator()

	private function attempt(array $object): TransitionAttempt {
		return new TransitionAttempt(
			write: new LifecycleWrite(newData: $object, oldData: ['reason' => 'old'], schemaSlug: 'case', field: 'status'),
			move: new TransitionMove(action: 'decide', from: 'open', to: 'closed')
		);
	}//end attempt()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function specs(): array {
		$reasoned = ['!!' => ['var' => 'object.reason']];
		return [
			'no condition' => [['message' => 'x'], []],
			'holds' => [['condition' => $reasoned], ['reason' => 'yes']],
			'unmet' => [['condition' => $reasoned], []],
			'scalar refuses' => [['condition' => 'scalar'], ['reason' => 'yes']],
			'author message' => [['condition' => $reasoned, 'message' => 'Eerst een reden.'], []],
		];
	}//end specs()

	/**
	 * @dataProvider specs
	 *
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $object
	 */
	public function testRefusalOfAnswersAsRefusal(array $spec, array $object): void {
		$evaluator = $this->evaluator(null);
		$expected  = $evaluator->refusal($spec, $object, ['reason' => 'old'], 'decide', 'open', 'closed', 'case', 'status');

		$this->assertSame($expected, $evaluator->refusalOf($spec, $this->attempt($object)));
	}//end testRefusalOfAnswersAsRefusal()

	public function testDocumentOfNamesTheCallerAndMatchesDocument(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$evaluator = $this->evaluator($user);

		$document = $evaluator->documentOf($this->attempt(['reason' => 'yes']));

		$this->assertSame(
			[
				'object' => ['reason' => 'yes'],
				'previous' => ['reason' => 'old'],
				'user' => ['uid' => 'alice', 'groups' => ['beslissers']],
				'transition' => ['action' => 'decide', 'from' => 'open', 'to' => 'closed'],
			],
			$document
		);
		$this->assertSame($document, $evaluator->document(['reason' => 'yes'], ['reason' => 'old'], 'decide', 'open', 'closed'));
	}//end testDocumentOfNamesTheCallerAndMatchesDocument()

	public function testNoSessionGivesAnEmptyCaller(): void {
		$document = $this->evaluator(null)->documentOf($this->attempt([]));

		$this->assertSame(['uid' => '', 'groups' => []], $document['user']);
	}//end testNoSessionGivesAnEmptyCaller()
}//end class
