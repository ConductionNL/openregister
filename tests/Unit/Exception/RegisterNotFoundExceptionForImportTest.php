<?php

/**
 * Unit tests for RegisterNotFoundException::forImport().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-clear-failure-when-auto-create-is-impossible-req-imp-ac-02
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\RegisterNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * The import refusal names the missing register and both remedies, as a 4xx.
 */
class RegisterNotFoundExceptionForImportTest extends TestCase {

	/**
	 * The refusal names the register, both remedies and answers 404.
	 *
	 * @return void
	 */
	public function testForImportNamesTheRegisterAndBothRemedies(): void {
		$refusal = RegisterNotFoundException::forImport(registerSlugOrId: 'orders');

		$this->assertSame(
			expected: "Register not found: 'orders' " . RegisterNotFoundException::IMPORT_REMEDIES,
			actual: $refusal->getMessage()
		);
		$this->assertStringContainsString(needle: 'Create the register first', haystack: $refusal->getMessage());
		$this->assertStringContainsString(needle: 'full register bundle', haystack: $refusal->getMessage());
		$this->assertSame(expected: 404, actual: $refusal->getCode());
	}//end testForImportNamesTheRegisterAndBothRemedies()

	/**
	 * Without remedies the message stays the original one, so other callers are unchanged.
	 *
	 * @return void
	 */
	public function testThePlainConstructorKeepsItsMessage(): void {
		$refusal = new RegisterNotFoundException(registerSlugOrId: 'orders');

		$this->assertSame(expected: "Register not found: 'orders'", actual: $refusal->getMessage());
		$this->assertSame(expected: 404, actual: $refusal->getCode());
	}//end testThePlainConstructorKeepsItsMessage()
}//end class
