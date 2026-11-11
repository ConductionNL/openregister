<?php

/**
 * A rule on `_expires` reads the record's own expiry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\RecordMetadataConditions;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The match envelope carries `expires`, so a delegation that ends on `_expires` works on a record.
 *
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Service\ComparableInstants
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 */
class MatchEnvelopeExpiresTest extends TestCase {

	/**
	 * A record that expires at the given moment.
	 *
	 * @param string|null $expires When it expires, or null for never.
	 * @param array       $data    The record's data.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(?string $expires, array $data = ['title' => 'x']): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid('zaak-1');
		$record->setObject($data);
		if ($expires !== null) {
			$record->setExpires(new DateTime($expires));
		}

		return $record;
	}//end record()

	/**
	 * A matcher with a real operator evaluator.
	 *
	 * @return ConditionMatcher The matcher.
	 */
	private function matcher(): ConditionMatcher {
		return new ConditionMatcher(
			new ConditionUserResolvers($this->createMock(IUserSession::class)),
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator(logger: new NullLogger()),
			new NullLogger()
		);
	}//end matcher()

	/**
	 * `_expires` is record metadata, so it is not refused.
	 *
	 * @return void
	 */
	public function testExpiresIsRecordMetadata(): void {
		$this->assertContains('expires', (new RecordMetadataConditions())->fields());
		$this->assertSame([], (new RecordMetadataConditions())->unknownKeys(match: ['_expires' => ['$gt' => '$now']]));
	}//end testExpiresIsRecordMetadata()

	/**
	 * The zaaktype delegation rule admits until the record expires, and not after.
	 *
	 * @return void
	 */
	public function testADelegationEndsWhenTheRecordExpires(): void {
		$rule = ['_expires' => ['$gt' => '$now']];

		$this->assertTrue($this->matcher()->objectMatchesConditions(object: $this->record('+1 day')->getMatchEnvelope(), match: $rule));
		$this->assertFalse($this->matcher()->objectMatchesConditions(object: $this->record('-1 day')->getMatchEnvelope(), match: $rule));
		$this->assertFalse($this->matcher()->objectMatchesConditions(object: $this->record(null)->getMatchEnvelope(), match: $rule));
	}//end testADelegationEndsWhenTheRecordExpires()

	/**
	 * A body `_expires` does not extend an expired record.
	 *
	 * @return void
	 */
	public function testTheBodyDoesNotAnswerForTheExpiry(): void {
		$envelope = $this->record('-1 day', ['_expires' => '2099-01-01', '@self' => ['expires' => '2099-01-01']])->getMatchEnvelope();

		$this->assertArrayNotHasKey('_expires', $envelope);
		$this->assertFalse($this->matcher()->objectMatchesConditions(object: $envelope, match: ['_expires' => ['$gt' => '$now']]));
	}//end testTheBodyDoesNotAnswerForTheExpiry()

	/**
	 * The rendered `@self` is unchanged: expiry is added to the match envelope only.
	 *
	 * @return void
	 */
	public function testTheRenderedSelfIsUnchanged(): void {
		$this->assertArrayNotHasKey('expires', $this->record('+1 day')->getObjectArray());
	}//end testTheRenderedSelfIsUnchanged()
}//end class
