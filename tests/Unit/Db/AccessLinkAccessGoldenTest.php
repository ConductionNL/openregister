<?php

/**
 * Golden test for AccessLink's access decisions: when a link is live, which
 * capabilities it declares and allows, and what it exposes. Pinned on the
 * code before its units were split, allowed and refused outcomes both.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins AccessLink's allowed and refused outcomes.
 */
class AccessLinkAccessGoldenTest extends TestCase {

	/**
	 * The fixed moment links are judged against.
	 */
	private const NOW = '2030-06-15T12:00:00+00:00';

	/**
	 * Link states and whether the link is live at NOW.
	 *
	 * @return array<string, array{string|null, string|null, bool, bool}>
	 */
	public static function liveCases(): array {
		return [
			'allowed: expires later' => ['2030-06-15T12:00:01+00:00', null, false, true],
			'refused: expires exactly now' => [self::NOW, null, false, false],
			'refused: expired' => ['2030-06-15T11:59:59+00:00', null, false, false],
			'refused: no expiry' => [null, null, false, false],
			'refused: revoked' => ['2031-01-01T00:00:00+00:00', '2030-01-01T00:00:00+00:00', false, false],
			'refused: revoked in the future still counts' => ['2031-01-01T00:00:00+00:00', '2031-01-01T00:00:00+00:00', false, false],
			'refused: disabled' => ['2031-01-01T00:00:00+00:00', null, true, false],
		];
	}//end liveCases()

	/**
	 * Whether the link is live.
	 *
	 * @param string|null $expires  The expiry.
	 * @param string|null $revoked  The revocation time.
	 * @param bool        $disabled Whether the link is switched off.
	 * @param bool        $live     Whether it is live.
	 *
	 * @return void
	 */
	#[DataProvider('liveCases')]
	public function testIsLive(?string $expires, ?string $revoked, bool $disabled, bool $live): void {
		$link = new AccessLink();
		if ($expires !== null) {
			$link->setExpiresAt(new DateTime($expires));
		}

		if ($revoked !== null) {
			$link->setRevokedAt(new DateTime($revoked));
		}

		$link->setDisabled($disabled);
		$this->assertSame($live, $link->isLive(new DateTime(self::NOW)));
	}//end testIsLive()

	/**
	 * Stored capability text and the capabilities it declares.
	 *
	 * @return array<string, array{string|null, array<int, string>}>
	 */
	public static function capabilityCases(): array {
		return [
			'null' => [null, []],
			'empty' => ['', []],
			'blank' => ['   ', []],
			'only commas' => [',, ,', []],
			'one' => ['read', ['read']],
			'case and spaces folded, order kept' => [' Upload , READ ', ['upload', 'read']],
			'unknown dropped' => ['read,delete,admin,comment', ['read', 'comment']],
			'duplicates dropped' => ['comment,COMMENT, comment ,read', ['comment', 'read']],
		];
	}//end capabilityCases()

	/**
	 * The declared capabilities.
	 *
	 * @param string|null        $stored   The stored text.
	 * @param array<int, string> $declared The declared list.
	 *
	 * @return void
	 */
	#[DataProvider('capabilityCases')]
	public function testDeclaredCapabilities(?string $stored, array $declared): void {
		$link = new AccessLink();
		$link->setCapabilities($stored);
		$this->assertSame($declared, $link->declaredCapabilities());
	}//end testDeclaredCapabilities()

	/**
	 * A link allows a declared capability, and refuses the rest.
	 *
	 * @return void
	 */
	public function testAllows(): void {
		$link = new AccessLink();
		$this->assertFalse($link->allows('read'));
		$link->setCapabilities('read,upload,delete');
		$this->assertTrue($link->allows('read'));
		$this->assertTrue($link->allows(' UPLOAD '));
		$this->assertFalse($link->allows('comment'));
		$this->assertFalse($link->allows('delete'));
		$this->assertFalse($link->allows(''));
	}//end testAllows()

	/**
	 * Password, principal and what is exposed.
	 *
	 * @return void
	 */
	public function testPasswordPrincipalAndExposure(): void {
		$link = new AccessLink();
		$this->assertFalse($link->hasPassword());
		$link->setPasswordHash('  ');
		$this->assertFalse($link->hasPassword());
		$link->setPasswordHash('$2y$hash');
		$this->assertTrue($link->hasPassword());

		$this->assertSame('link:', $link->principalId());
		$link->setUuid('u-1');
		$this->assertSame('link:u-1', $link->principalName());
		$link->setLabel(' Team ');
		$this->assertSame('Team', $link->principalName());
		$link->setLabel('   ');
		$this->assertSame('link:u-1', $link->principalName());

		$link->setId(3);
		$link->setAnchor('anchor-secret');
		$link->setSubjectType('object');
		$link->setSubjectId('o-1');
		$link->setCapabilities('read,bogus');
		$link->setLabel('L');
		$link->setCreatedBy('alice');
		$link->setCreated(new DateTime('2026-01-01T00:00:00+00:00'));
		$link->setExpiresAt(new DateTime('2999-01-01T00:00:00+00:00'));
		$link->setLastUsedAt(new DateTime('2026-02-01T00:00:00+00:00'));
		$link->setUseCount(4);
		$this->assertSame(
			[
				'id' => 3,
				'uuid' => 'u-1',
				'anchor' => 'anchor-secret',
				'subjectType' => 'object',
				'subjectId' => 'o-1',
				'capabilities' => ['read'],
				'label' => 'L',
				'hasPassword' => true,
				'createdBy' => 'alice',
				'created' => '2026-01-01T00:00:00+00:00',
				'expiresAt' => '2999-01-01T00:00:00+00:00',
				'revokedAt' => null,
				'disabled' => false,
				'lastUsedAt' => '2026-02-01T00:00:00+00:00',
				'useCount' => 4,
				'live' => true,
			],
			$link->jsonSerialize()
		);
		$this->assertArrayNotHasKey('passwordHash', $link->jsonSerialize());
		$this->assertSame(
			['subjectType' => 'object', 'capabilities' => ['read'], 'label' => 'L', 'expiresAt' => '2999-01-01T00:00:00+00:00'],
			$link->publicDescriptor()
		);
		$link->setExpiresAt(new DateTime('2000-01-01T00:00:00+00:00'));
		$this->assertFalse($link->jsonSerialize()['live']);
		$this->assertSame(
			['id', 'uuid', 'anchor', 'subjectType', 'subjectId', 'capabilities', 'label', 'passwordHash', 'createdBy', 'created', 'expiresAt', 'revokedAt', 'disabled', 'lastUsedAt', 'useCount'],
			array_keys($link->getFieldTypes())
		);
	}//end testPasswordPrincipalAndExposure()
}//end class
