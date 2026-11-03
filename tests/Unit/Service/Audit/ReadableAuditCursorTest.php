<?php

/**
 * The scoped audit list's cursor leaves sealed and opens only to an offset.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use Exception;
use OCA\OpenRegister\Service\Audit\ReadableAuditCursor;
use OCA\OpenRegister\Tests\Support\BuildsAuditCursors;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Audit\ReadableAuditCursor
 */
final class ReadableAuditCursorTest extends TestCase {
	use BuildsAuditCursors;

	/**
	 * A seal over a crypto double that decrypts every token to the given plaintext.
	 *
	 * @param string $plaintext What every token opens to.
	 *
	 * @return ReadableAuditCursor The seal.
	 */
	private function openingTo(string $plaintext): ReadableAuditCursor {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn($plaintext);

		return new ReadableAuditCursor($crypto);
	}//end openingTo()

	public function testASealedOffsetOpensToThatOffset(): void {
		$cursors = $this->auditCursors();

		self::assertSame(2400, $cursors->open(cursor: $cursors->seal(offset: 2400)));
		self::assertSame(0, $cursors->open(cursor: $cursors->seal(offset: 0)));
	}//end testASealedOffsetOpensToThatOffset()

	public function testTheOffsetIsSealedWithTheCursorPurpose(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->once())->method('encrypt')->with('audit-readable-cursor:17')->willReturn('t');

		self::assertSame('t', (new ReadableAuditCursor($crypto))->seal(offset: 17));
	}//end testTheOffsetIsSealedWithTheCursorPurpose()

	public function testNothingStartsAtTheTop(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('decrypt');
		$cursors = new ReadableAuditCursor($crypto);

		self::assertSame(0, $cursors->open(cursor: null));
		self::assertSame(0, $cursors->open(cursor: ''));
	}//end testNothingStartsAtTheTop()

	public function testAPlainOffsetIsReadAsAnOffset(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('decrypt');
		$cursors = new ReadableAuditCursor($crypto);

		self::assertSame(40, $cursors->open(cursor: '40'));
		self::assertSame(40, $cursors->open(cursor: 40));
		self::assertSame(0, $cursors->open(cursor: -5));
	}//end testAPlainOffsetIsReadAsAnOffset()

	public function testATamperedCursorStartsAtTheTop(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new Exception('HMAC does not match.'));

		self::assertSame(0, (new ReadableAuditCursor($crypto))->open(cursor: 'tampered'));
	}//end testATamperedCursorStartsAtTheTop()

	public function testATokenSealedForSomethingElseStartsAtTheTop(): void {
		self::assertSame(0, $this->openingTo(plaintext: '2400')->open(cursor: 'other'));
		self::assertSame(0, $this->openingTo(plaintext: 'tenant-key:2400')->open(cursor: 'other'));
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor;2400')->open(cursor: 'other'));
	}//end testATokenSealedForSomethingElseStartsAtTheTop()

	public function testASealedNonNumberStartsAtTheTop(): void {
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor:-3')->open(cursor: 'x'));
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor:')->open(cursor: 'x'));
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor:12abc')->open(cursor: 'x'));
	}//end testASealedNonNumberStartsAtTheTop()
}//end class
