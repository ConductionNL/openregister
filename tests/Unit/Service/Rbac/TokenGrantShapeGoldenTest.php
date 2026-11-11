<?php

/**
 * Golden test for TokenGrant's stored shape and its allowed and refused answers.
 *
 * Pinned on the code before the constructor was bundled: what fromStored()
 * reads back through toArray() and the public properties, that an absent grant
 * is null while a malformed one permits nothing, and what permits(), covers(),
 * isExpired() and daysLeft() answer, allowed AND refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\TokenGrant
 */
final class TokenGrantShapeGoldenTest extends TestCase {

	/**
	 * A fully scoped stored grant.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		return [
			'verbs' => ['read', 'manage', 7],
			'registers' => ['zaken'],
			'schemas' => ['zaak', 3],
			'match' => ['status' => 'open'],
			'expiresAt' => '2030-01-11T00:00:00+00:00',
			'rateLimit' => '60',
		];
	}//end stored()

	/**
	 * The stored grant reads back field for field.
	 *
	 * @return void
	 */
	public function testFullGrantReadsBack(): void {
		$grant = TokenGrant::fromStored(self::stored(), 'tok-1');

		$this->assertSame(
			[
				'verbs' => ['read', 'manage', '7'],
				'registers' => ['zaken'],
				'schemas' => ['zaak', '3'],
				'match' => ['status' => 'open'],
				'expiresAt' => '2030-01-11T00:00:00+00:00',
				'rateLimit' => 60,
				'tokenId' => 'tok-1',
			],
			$grant->toArray()
		);
		$this->assertSame(['read', 'manage', '7'], $grant->verbs);
		$this->assertSame(['zaken'], $grant->registers);
		$this->assertSame(['zaak', '3'], $grant->schemas);
		$this->assertSame(['status' => 'open'], $grant->match);
		$this->assertSame(60, $grant->rateLimit);
		$this->assertSame('tok-1', $grant->tokenId);
		$this->assertSame('2030-01-11T00:00:00+00:00', $grant->expiresAt?->format(DATE_ATOM));
	}//end testFullGrantReadsBack()

	/**
	 * An unscoped grant leaves every axis null.
	 *
	 * @return void
	 */
	public function testUnscopedGrantLeavesAxesNull(): void {
		$grant = TokenGrant::fromStored(['verbs' => ['read'], 'match' => 'x', 'rateLimit' => 'many', 'expiresAt' => 'not a date']);

		$this->assertSame(
			['verbs' => ['read'], 'registers' => null, 'schemas' => null, 'match' => null, 'expiresAt' => null, 'rateLimit' => null, 'tokenId' => ''],
			$grant->toArray()
		);
		$this->assertTrue($grant->covers(null, null));
	}//end testUnscopedGrantLeavesAxesNull()

	/**
	 * Absent is null; malformed permits nothing.
	 *
	 * @return void
	 */
	public function testAbsentAndMalformed(): void {
		$this->assertNull(TokenGrant::fromStored(null, 'tok-1'));

		foreach (['read', 5, ['verbs' => 'read'], ['schemas' => ['zaak']]] as $malformed) {
			$grant = TokenGrant::fromStored($malformed, 'tok-2');
			$this->assertTrue($grant->isEmpty());
			$this->assertFalse($grant->permits('read'));
			$this->assertSame('tok-2', $grant->tokenId);
			$this->assertSame(
				['verbs' => [], 'registers' => null, 'schemas' => null, 'match' => null, 'expiresAt' => null, 'rateLimit' => null, 'tokenId' => 'tok-2'],
				$grant->toArray()
			);
		}
	}//end testAbsentAndMalformed()

	/**
	 * The verbs, scope and expiry answers, allowed and refused.
	 *
	 * @return void
	 */
	public function testAllowedAndRefused(): void {
		$grant = TokenGrant::fromStored(self::stored());
		$now = new DateTimeImmutable('2030-01-01T00:00:00+00:00');

		$this->assertFalse($grant->isEmpty());
		$this->assertTrue($grant->permits('read'));
		$this->assertFalse($grant->permits('manage'));
		$this->assertFalse($grant->permits('delete'));

		$this->assertTrue($grant->covers('zaak', 'zaken'));
		$this->assertFalse($grant->covers('zaak', 'other'));
		$this->assertFalse($grant->covers('other', 'zaken'));
		$this->assertFalse($grant->covers(null, 'zaken'));
		$this->assertFalse($grant->covers('zaak', null));

		$this->assertFalse($grant->isExpired($now));
		$this->assertTrue($grant->isExpired(new DateTimeImmutable('2030-01-11T00:00:00+00:00')));
		$this->assertSame(10, $grant->daysLeft($now));

		$noEnd = TokenGrant::fromStored(['verbs' => ['read']]);
		$this->assertTrue($noEnd->isExpired($now));
		$this->assertNull($noEnd->daysLeft($now));
	}//end testAllowedAndRefused()
}//end class
