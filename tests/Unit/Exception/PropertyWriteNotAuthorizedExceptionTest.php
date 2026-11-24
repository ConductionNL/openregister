<?php

/**
 * PropertyWriteNotAuthorizedException carries the refused property names and
 * answers 403, so the controller can name them without leaking a server fault.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-field-access-editor/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\PropertyWriteNotAuthorizedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Exception\PropertyWriteNotAuthorizedException
 */
final class PropertyWriteNotAuthorizedExceptionTest extends TestCase {

	/**
	 * The names, the message naming them, and the 403 status are all carried.
	 *
	 * @return void
	 */
	public function testItCarriesTheNamesTheMessageAndThe403(): void {
		$previous = new RuntimeException('underlying');
		$exception = new PropertyWriteNotAuthorizedException(['salaris', 'besluitdatum'], $previous);

		$this->assertSame(['salaris', 'besluitdatum'], $exception->getProperties());
		$this->assertSame('You are not authorized to modify the following properties: salaris, besluitdatum', $exception->getMessage());
		$this->assertSame(403, $exception->getHttpStatusCode());
		$this->assertSame(403, PropertyWriteNotAuthorizedException::HTTP_STATUS);
		$this->assertSame($previous, $exception->getPrevious());
	}//end testItCarriesTheNamesTheMessageAndThe403()

	/**
	 * With no refused names the message still reads, and the list is empty.
	 *
	 * @return void
	 */
	public function testAnEmptyListStillReads(): void {
		$exception = new PropertyWriteNotAuthorizedException([]);

		$this->assertSame([], $exception->getProperties());
		$this->assertSame('You are not authorized to modify the following properties: ', $exception->getMessage());
	}//end testAnEmptyListStillReads()
}//end class
