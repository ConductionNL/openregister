<?php

/**
 * RetentionScanCriteria: an object is accepted unless the criteria callback returns false.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\RetentionScanCriteria;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Archival\RetentionScanCriteria
 */
class RetentionScanCriteriaTest extends TestCase {

	public function testOnlyAStrictFalseRejectsTheObject(): void {
		$object = $this->createMock(ObjectEntity::class);

		$this->assertFalse((new RetentionScanCriteria(static fn (): bool => false, 3))->accepts($object));
		$this->assertTrue((new RetentionScanCriteria(static fn (): bool => true, 3))->accepts($object));
		$this->assertTrue((new RetentionScanCriteria(static fn (): ?bool => null, 3))->accepts($object));
		$this->assertTrue((new RetentionScanCriteria(static fn (): int => 0, 3))->accepts($object));
	}

	public function testTheCallbackReceivesTheObjectAndTheRemainingBudgetIsKept(): void {
		$object = $this->createMock(ObjectEntity::class);
		$seen = null;
		$criteria = new RetentionScanCriteria(
			static function (ObjectEntity $candidate) use (&$seen): bool {
				$seen = $candidate;
				return true;
			},
			7
		);

		$criteria->accepts($object);

		$this->assertSame($object, $seen);
		$this->assertSame(7, $criteria->remaining);
	}
}
