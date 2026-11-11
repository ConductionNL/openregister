<?php

/**
 * The conflict raised when an organisation name is taken.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\OrganisationSlugConflictException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The conflict is a 409 that names the taken slug and keeps its cause.
 *
 * @covers \OCA\OpenRegister\Exception\OrganisationSlugConflictException
 */
final class OrganisationSlugConflictExceptionTest extends TestCase {

	/**
	 * The conflict carries 409, a user-facing message, the slug and the cause.
	 *
	 * @return void
	 */
	public function testTheConflictNamesTheSlugAndKeepsItsCause(): void {
		$cause = new RuntimeException('unique constraint');
		$conflict = new OrganisationSlugConflictException(slug: 'gemeente-x', previous: $cause);

		$this->assertSame('gemeente-x', $conflict->getSlug());
		$this->assertSame(409, $conflict->getCode());
		$this->assertSame('An organisation with this name already exists.', $conflict->getMessage());
		$this->assertSame($cause, $conflict->getPrevious());
	}//end testTheConflictNamesTheSlugAndKeepsItsCause()
}//end class
