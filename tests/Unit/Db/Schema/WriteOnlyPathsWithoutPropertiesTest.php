<?php

/**
 * WriteOnlyPathsDeclaration skips the root check on a schema without properties.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\Schema
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema\WriteOnlyPathsDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema\WriteOnlyPathsDeclaration
 */
class WriteOnlyPathsWithoutPropertiesTest extends TestCase {

	/**
	 * Without properties there is nothing to check a root against, so any root is accepted.
	 *
	 * @return void
	 */
	public function testWithoutPropertiesAnyRootIsAccepted(): void {
		$this->assertSame(['secret.pin'], (new WriteOnlyPathsDeclaration())->validateWriteOnlyPathsValue(['secret.pin', 'secret.pin'], null));
	}//end testWithoutPropertiesAnyRootIsAccepted()

	/**
	 * With properties the same undeclared root is refused.
	 *
	 * @return void
	 */
	public function testWithPropertiesAnUndeclaredRootIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('is rooted at "secret", which is not a declared property of this schema');

		(new WriteOnlyPathsDeclaration())->validateWriteOnlyPathsValue(['secret.pin'], ['title' => []]);
	}//end testWithPropertiesAnUndeclaredRootIsRefused()
}//end class
