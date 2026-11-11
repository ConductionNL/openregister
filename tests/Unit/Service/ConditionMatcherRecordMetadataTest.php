<?php

/**
 * ConditionMatcher: metadata conditions on an object that carries its record's `@self`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An underscore-prefixed condition reads the record's `@self`, never a body key, once `@self` is there.
 *
 * @covers \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ConditionMatcherRecordMetadataTest extends TestCase {

	/**
	 * The matcher under test.
	 *
	 * @var ConditionMatcher
	 */
	private ConditionMatcher $matcher;

	/**
	 * Build the matcher with a real operator evaluator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->matcher = new ConditionMatcher(
			new ConditionUserResolvers($this->createMock(IUserSession::class)),
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator($this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Published is not record metadata: a `_published` rule is an error that fails closed, whatever the body says.
	 *
	 * @return void
	 */
	public function testAPublishedRuleFailsClosedWhateverTheBodySays(): void {
		$object = ['title' => 'x', '_published' => '2020-01-01 00:00:00', '@self' => ['id' => 'obj-1', 'organisation' => 'org-a']];

		$this->assertTrue($this->matcher->namesUnknownMetadata(match: ['_published' => true], object: $object));
		$this->assertFalse($this->matcher->objectMatchesConditions(object: $object, match: ['_published' => '2020-01-01 00:00:00']));
		$this->assertFalse($this->matcher->objectMatchesConditions(object: $object, match: ['_published' => ['$lte' => '2030-01-01 00:00:00']]));
		$this->assertFalse($this->matcher->objectMatchesConditions(object: $object, match: ['_published' => null]));
	}//end testAPublishedRuleFailsClosedWhateverTheBodySays()

	/**
	 * `_uuid` is record metadata: it reads the record's uuid, which `@self` carries as `id`.
	 *
	 * @return void
	 */
	public function testAUuidRuleReadsTheRecordsUuid(): void {
		$object = ['_uuid' => 'forged', '@self' => ['id' => 'obj-1', 'organisation' => 'org-a', 'owner' => null]];

		$this->assertFalse($this->matcher->namesUnknownMetadata(match: ['_uuid' => 'obj-1']));
		$this->assertTrue($this->matcher->objectMatchesConditions(object: $object, match: ['_uuid' => 'obj-1']));
		$this->assertFalse($this->matcher->objectMatchesConditions(object: $object, match: ['_uuid' => 'forged']));
	}//end testAUuidRuleReadsTheRecordsUuid()

	/**
	 * A field the record holds as empty is empty, not unknown: a null condition on it is met.
	 *
	 * @return void
	 */
	public function testAnEmptyRecordFieldIsNotUnknown(): void {
		$object = ['@self' => ['id' => 'obj-1', 'owner' => null]];

		$this->assertTrue($this->matcher->objectMatchesConditions(object: $object, match: ['_owner' => null]));
	}//end testAnEmptyRecordFieldIsNotUnknown()

	/**
	 * A record field that is empty is not filled in by a body key of the same name.
	 *
	 * @return void
	 */
	public function testAnEmptyRecordFieldIsNotFilledByTheBody(): void {
		$object = ['_organisation' => 'org-a', '@self' => ['organisation' => null]];

		$this->assertFalse($this->matcher->objectMatchesConditions(object: $object, match: ['_organisation' => 'org-a']));
	}//end testAnEmptyRecordFieldIsNotFilledByTheBody()

	/**
	 * The record's own metadata still answers, and a body property without the underscore still matches.
	 *
	 * @return void
	 */
	public function testTheRecordAndPlainPropertiesStillAnswer(): void {
		$object = ['status' => 'open', '@self' => ['organisation' => 'org-a']];

		$this->assertTrue($this->matcher->objectMatchesConditions(object: $object, match: ['_organisation' => 'org-a', 'status' => 'open']));
	}//end testTheRecordAndPlainPropertiesStillAnswer()

	/**
	 * An object without any `@self` keeps reading underscore keys from its body.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutSelfStillReadsItsUnderscoreKeys(): void {
		$this->assertTrue($this->matcher->objectMatchesConditions(object: ['_organisation' => 'org-a'], match: ['_organisation' => 'org-a']));
	}//end testAnObjectWithoutSelfStillReadsItsUnderscoreKeys()
}//end class
