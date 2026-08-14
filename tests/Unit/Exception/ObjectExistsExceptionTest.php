<?php

/**
 * Unit tests for ObjectExistsException.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace Unit\Exception;

use OCA\OpenRegister\Exception\ObjectExistsException;
use PHPUnit\Framework\TestCase;

final class ObjectExistsExceptionTest extends TestCase {
	/**
	 * 409 Conflict, not 403.
	 *
	 * A losing claim reported as "forbidden" is indistinguishable from a
	 * permissions problem, and the caller cannot tell it simply lost a race —
	 * which is the entire reason for asking for insert-only semantics.
	 *
	 * @return void
	 */
	public function testExceptionCarriesStatus409(): void {
		$exception = new ObjectExistsException();
		self::assertSame(expected: 409, actual: $exception->getCode());

	}//end testExceptionCarriesStatus409()

	/**
	 * The conflicting identifier is retrievable without parsing the message.
	 *
	 * @return void
	 */
	public function testExceptionExposesUuid(): void {
		$exception = new ObjectExistsException(
			message: 'An object with identifier "abc" already exists.',
			uuid: 'abc'
		);

		self::assertSame(expected: 'abc', actual: $exception->getUuid());

	}//end testExceptionExposesUuid()

	/**
	 * The uuid is optional — a caller may raise it without one.
	 *
	 * @return void
	 */
	public function testUuidIsNullWhenNotSupplied(): void {
		$exception = new ObjectExistsException();
		self::assertNull(actual: $exception->getUuid());

	}//end testUuidIsNullWhenNotSupplied()
}//end class
