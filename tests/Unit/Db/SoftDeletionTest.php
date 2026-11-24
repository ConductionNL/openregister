<?php

/**
 * Unit tests for SoftDeletion's recovery window.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\SoftDeletion;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A soft deletion without a usable retention takes the default window of 30 days.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class SoftDeletionTest extends TestCase {

	public function testTheDefaultWindowIsThirtyDaysAndTheServiceReadsTheSameValue(): void {
		self::assertSame(30, SoftDeletion::DEFAULT_RETENTION_DAYS);
		self::assertSame(SoftDeletion::DEFAULT_RETENTION_DAYS, DeletionWindowService::DEFAULT_RETENTION_DAYS);
	}

	/**
	 * @return array<string, array{0: int|null, 1: int}>
	 */
	public static function retentions(): array {
		return [
			'null takes the default' => [null, 30],
			'zero takes the default' => [0, 30],
			'negative takes the default' => [-5, 30],
			'a positive retention is kept' => [7, 7],
		];
	}

	#[DataProvider('retentions')]
	public function testDaysFallsBackToTheDefaultBelowOne(?int $retention, int $expected): void {
		self::assertSame($expected, (new SoftDeletion(reason: 'r', retentionDays: $retention))->days());
	}

	public function testMetadataPutsThePurgeDateAtTheEndOfTheWindow(): void {
		$metadata = (new SoftDeletion(reason: 'gone', retentionDays: null))->metadataFor(userId: 'alice', now: new DateTime('2026-01-01T00:00:00+00:00'));

		self::assertSame('alice', $metadata['deletedBy']);
		self::assertSame('gone', $metadata['deletedReason']);
		self::assertSame(30, $metadata['retentionPeriod']);
		self::assertSame('2026-01-31T00:00:00+00:00', $metadata['purgeDate']);
	}
}
