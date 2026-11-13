<?php

/**
 * Which decision RelationDecisionService checks ownership against before a change.
 *
 * Pins allowed and refused outcomes: the current decision is checked; without
 * one, a linked decision row is checked as a placeholder owned by nobody named;
 * without either (an empty link included) nothing is checked and the change is
 * allowed; a refusal is thrown as DecisionOwnedException with its message.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Decision
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Decision;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Service\Decision\DecisionOwnedException;
use OCA\OpenRegister\Service\Decision\DecisionOwnership;
use OCA\OpenRegister\Service\Decision\DecisionQuestion;
use OCA\OpenRegister\Service\Decision\DecisionRun;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Decision\RelationDecisionStores;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The ownership check before a decision change, golden.
 *
 * @covers \OCA\OpenRegister\Service\Decision\RelationDecisionService
 * @uses \OCA\OpenRegister\Db\Decision
 * @uses \OCA\OpenRegister\Db\EntityRelation
 * @uses \OCA\OpenRegister\Service\Decision\DecisionOwnedException
 * @uses \OCA\OpenRegister\Service\Decision\DecisionQuestion
 * @uses \OCA\OpenRegister\Service\Decision\DecisionRun
 * @uses \OCA\OpenRegister\Service\Decision\ReviewPolicy
 */
final class RelationDecisionOwnedDecisionTest extends TestCase {

	/**
	 * Every decision the ownership check was asked about, as [uuid, decidedBy, actor].
	 *
	 * @var array<int, array{0: string|null, 1: string|null, 2: string}>
	 */
	private array $asked = [];

	/**
	 * Run assertMayChange with this current decision and link; answer the outcome.
	 *
	 * @param Decision|null $current The relation's current decision.
	 * @param string|null   $linked  The linked decision uuid.
	 * @param array|null    $refusal What the ownership check answers.
	 *
	 * @return string `allowed`, or the refusal message.
	 */
	private function outcome(?Decision $current, ?string $linked, ?array $refusal): string {
		$ownership = $this->createMock(DecisionOwnership::class);
		$ownership->method('refusal')->willReturnCallback(
			function (Decision $decision, DecisionQuestion $question) use ($refusal): ?array {
				$this->asked[] = [$decision->getUuid(), $decision->getDecidedBy(), $question->actorId];

				return $refusal;
			}
		);
		$policies = $this->createMock(ReviewPolicyResolver::class);
		$policies->method('forRelation')->willReturn(['policy' => ReviewPolicy::fromDeclaration(null), 'subject' => []]);
		$stores = (new ReflectionClass(RelationDecisionStores::class))->newInstanceWithoutConstructor();
		$service = new RelationDecisionService($stores, $ownership, $policies);
		$actor = $this->createMock(IUser::class);
		$actor->method('getUID')->willReturn('rita');

		try {
			(new ReflectionMethod(RelationDecisionService::class, 'assertMayChange'))->invoke($service, new DecisionRun(relation: new EntityRelation(), actor: $actor, linkedUuid: $linked, current: $current));
		} catch (DecisionOwnedException $e) {
			return $e->getMessage();
		}

		return 'allowed';
	}//end outcome()

	/**
	 * A decision owned by `vera`.
	 *
	 * @return Decision The decision.
	 */
	private static function current(): Decision {
		$decision = new Decision();
		$decision->setUuid('d-cur');
		$decision->setDecidedBy('vera');

		return $decision;
	}//end current()

	/**
	 * The current decision is checked; refused or allowed as the ownership check says.
	 *
	 * @return void
	 */
	public function testTheCurrentDecisionIsChecked(): void {
		$this->assertSame('This decision belongs to vera.', $this->outcome(current: self::current(), linked: 'd-link', refusal: ['decidedBy' => 'vera']));
		$this->assertSame('allowed', $this->outcome(current: self::current(), linked: null, refusal: null));
		$this->assertSame([['d-cur', 'vera', 'rita'], ['d-cur', 'vera', 'rita']], $this->asked);
	}//end testTheCurrentDecisionIsChecked()

	/**
	 * Without a current decision the linked row is checked as an unnamed placeholder.
	 *
	 * @return void
	 */
	public function testALinkedDecisionIsCheckedAsAPlaceholder(): void {
		$this->assertSame('This decision belongs to another reviewer.', $this->outcome(current: null, linked: 'd-link', refusal: ['reason' => 'owned']));
		$this->assertSame([['d-link', '', 'rita']], $this->asked);
	}//end testALinkedDecisionIsCheckedAsAPlaceholder()

	/**
	 * Without a current decision or a non-empty link nothing is checked and the change is allowed.
	 *
	 * @return void
	 */
	public function testNothingToOwnIsAllowedUnchecked(): void {
		$this->assertSame('allowed', $this->outcome(current: null, linked: null, refusal: ['decidedBy' => 'vera']));
		$this->assertSame('allowed', $this->outcome(current: null, linked: '', refusal: ['decidedBy' => 'vera']));
		$this->assertSame([], $this->asked);
	}//end testNothingToOwnIsAllowedUnchecked()
}//end class
