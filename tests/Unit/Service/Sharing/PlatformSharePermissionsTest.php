<?php

/**
 * Unit tests for PlatformSharePermissions: each platform bit is a verb or a refusal naming it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Sharing\PlatformSharePermissions;
use OCP\Constants;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sharing\PlatformSharePermissions
 */
final class PlatformSharePermissionsTest extends TestCase {

	public function testTheReadBitGrantsReadingAndNothingElse(): void {
		$this->assertSame(['read'], (new PlatformSharePermissions())->verbsFor(permissions: Constants::PERMISSION_READ));
	}

	public function testReadAndUpdateMapInBitOrder(): void {
		$this->assertSame(
			['read', 'update'],
			(new PlatformSharePermissions())->verbsFor(permissions: Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE)
		);
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function refusedBits(): array {
		return [
			'create' => [Constants::PERMISSION_CREATE, 'Permission bit 4 (create) maps to no object verb'],
			'delete' => [Constants::PERMISSION_DELETE, 'Permission bit 8 (delete) maps to no object verb'],
			'share' => [Constants::PERMISSION_SHARE, 'Permission bit 16 (share) maps to no object verb'],
			'undefined' => [64, 'Permission bit 64 is not a platform permission and maps to no object verb'],
		];
	}

	/**
	 * @dataProvider refusedBits
	 */
	public function testAMeaninglessBitIsRefusedNamingIt(int $bit, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new PlatformSharePermissions())->verbsFor(permissions: Constants::PERMISSION_READ | $bit);
	}

	public function testTheAllMaskIsRefusedAtItsFirstUnmappedBitRatherThanNarrowed(): void {
		$this->expectExceptionMessage('Permission bit 4 (create)');

		(new PlatformSharePermissions())->verbsFor(permissions: Constants::PERMISSION_ALL);
	}

	public function testAShareGrantingNothingIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A share must grant at least one permission');

		(new PlatformSharePermissions())->verbsFor(permissions: 0);
	}
}
