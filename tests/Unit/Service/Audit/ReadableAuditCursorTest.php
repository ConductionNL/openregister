<?php

/**
 * The scoped audit list's cursor opens only under the scope it was sealed to.
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

	public function testASealedOffsetOpensToThatOffsetUnderItsScope(): void {
		$cursors = $this->auditCursors();
		$scope = $cursors->scope(userId: 'alice', query: ['filters' => ['user' => 'bob'], 'search' => '']);

		self::assertSame(2400, $cursors->open(cursor: $cursors->seal(offset: 2400, scope: $scope), scope: $scope));
		self::assertSame(0, $cursors->open(cursor: $cursors->seal(offset: 0, scope: $scope), scope: $scope));
	}//end testASealedOffsetOpensToThatOffsetUnderItsScope()

	public function testASealedOffsetDoesNotOpenUnderAnotherScope(): void {
		$cursors = $this->auditCursors();
		$scope = $cursors->scope(userId: 'alice', query: ['filters' => ['user' => 'bob'], 'search' => '']);
		$cursor = $cursors->seal(offset: 2400, scope: $scope);

		foreach ([
			$cursors->scope(userId: 'carol', query: ['filters' => ['user' => 'bob'], 'search' => '']),
			$cursors->scope(userId: 'alice', query: ['filters' => ['user' => 'dave'], 'search' => '']),
			$cursors->scope(userId: 'alice', query: ['filters' => ['user' => 'bob'], 'search' => 'x']),
		] as $other) {
			self::assertSame(0, $cursors->open(cursor: $cursor, scope: $other));
		}
	}//end testASealedOffsetDoesNotOpenUnderAnotherScope()

	public function testTheScopeNamesTheCallerAndTheQuery(): void {
		$cursors = $this->auditCursors();

		self::assertSame(
			hash('sha256', (string)json_encode(['alice', ['filters' => [], 'search' => '']])),
			$cursors->scope(userId: 'alice', query: ['filters' => [], 'search' => ''])
		);
	}//end testTheScopeNamesTheCallerAndTheQuery()

	public function testTheOffsetIsSealedWithThePurposeAndTheScope(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->once())->method('encrypt')->with('audit-readable-cursor:s1:17')->willReturn('t');

		self::assertSame('t', (new ReadableAuditCursor($crypto))->seal(offset: 17, scope: 's1'));
	}//end testTheOffsetIsSealedWithThePurposeAndTheScope()

	public function testNothingAndPlainNumbersStartAtTheTop(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('decrypt');
		$cursors = new ReadableAuditCursor($crypto);

		foreach ([null, '', '40', 40, '0', -5] as $cursor) {
			self::assertSame(0, $cursors->open(cursor: $cursor, scope: 's1'));
		}
	}//end testNothingAndPlainNumbersStartAtTheTop()

	public function testATamperedCursorStartsAtTheTop(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new Exception('HMAC does not match.'));

		self::assertSame(0, (new ReadableAuditCursor($crypto))->open(cursor: 'tampered', scope: 's1'));
	}//end testATamperedCursorStartsAtTheTop()

	public function testATokenSealedForSomethingElseStartsAtTheTop(): void {
		self::assertSame(0, $this->openingTo(plaintext: '2400')->open(cursor: 'other', scope: 's1'));
		self::assertSame(0, $this->openingTo(plaintext: 'tenant-key:2400')->open(cursor: 'other', scope: 's1'));
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor;s1:2400')->open(cursor: 'other', scope: 's1'));
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor:2400')->open(cursor: 'other', scope: 's1'));
		self::assertSame(2400, $this->openingTo(plaintext: 'audit-readable-cursor:s1:2400')->open(cursor: 'other', scope: 's1'));
	}//end testATokenSealedForSomethingElseStartsAtTheTop()

	public function testAWalkWithoutAScopeOpensNoCursor(): void {
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor::40')->open(cursor: 'x', scope: null));
		self::assertNull($this->auditCursors()->scope(userId: 'alice', query: ['filters' => [], 'search' => "\xff"]));
	}//end testAWalkWithoutAScopeOpensNoCursor()

	public function testASealedNonNumberStartsAtTheTop(): void {
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor:s1:-3')->open(cursor: 'x', scope: 's1'));
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor:s1:')->open(cursor: 'x', scope: 's1'));
		self::assertSame(0, $this->openingTo(plaintext: 'audit-readable-cursor:s1:12abc')->open(cursor: 'x', scope: 's1'));
	}//end testASealedNonNumberStartsAtTheTop()
}//end class
