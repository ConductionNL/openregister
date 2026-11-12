<?php

/**
 * Grant terms keep their permissions and verbs when dated, reading only non-blank strings.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Service\Rbac\GrantTerms;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\GrantTerms
 */
class GrantTermsTest extends TestCase {

	public function testTheDefaultsGrantReadOnlyAndAreUndated(): void {
		$terms = new GrantTerms();

		$this->assertSame(1, $terms->permissions);
		$this->assertSame([], $terms->verbs);
		$this->assertNull($terms->expiresAt);
		$this->assertNull($terms->sharedAt);
	}

	public function testDatedReturnsANewTermsWithBothMoments(): void {
		$terms = new GrantTerms(permissions: 15, verbs: ['read', 'update']);
		$dated = $terms->dated('2030-01-02 03:04:05', '2029-12-31');

		$this->assertNotSame($terms, $dated);
		$this->assertNull($terms->expiresAt);
		$this->assertSame(15, $dated->permissions);
		$this->assertSame(['read', 'update'], $dated->verbs);
		$this->assertInstanceOf(DateTime::class, $dated->expiresAt);
		$this->assertSame('2030-01-02 03:04:05', $dated->expiresAt->format('Y-m-d H:i:s'));
		$this->assertSame('2029-12-31', $dated->sharedAt->format('Y-m-d'));
	}

	public function testBlankAndNonStringMomentsAreNull(): void {
		$stamp = new DateTime('2030-01-01');
		$dated = (new GrantTerms(expiresAt: $stamp, sharedAt: $stamp))->dated('   ', $stamp);

		$this->assertNull($dated->expiresAt);
		$this->assertNull($dated->sharedAt);

		$none = (new GrantTerms())->dated(null, 1700000000);
		$this->assertNull($none->expiresAt);
		$this->assertNull($none->sharedAt);
	}
}
