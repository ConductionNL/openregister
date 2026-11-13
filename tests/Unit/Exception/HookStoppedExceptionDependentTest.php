<?php

/**
 * A cascade's refusal names neither the dependent object nor who holds it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\HookStoppedException;
use PHPUnit\Framework\TestCase;

/**
 * The refusal for a dependent object.
 */
class HookStoppedExceptionDependentTest extends TestCase {

	/**
	 * The code and status survive; the holder and the dependent's uuid do not.
	 *
	 * @return void
	 */
	public function testTheDependentRefusalKeepsCodeAndStatusOnly(): void {
		$holder = 'Cannot delete object: Object is locked by Alice Example. Please unlock the object before attempting to delete it.';
		$refusal = new HookStoppedException(
			message: $holder,
			errors: ['code' => 'object-locked', 'message' => $holder, 'status' => 423, 'uuid' => 'child-uuid']
		);

		$dependent = HookStoppedException::forDependent(refusal: $refusal);

		$this->assertStringNotContainsString(needle: 'Alice', haystack: $dependent->getMessage());
		$this->assertSame(
			expected: ['message' => $dependent->getMessage(), 'code' => 'object-locked', 'status' => 423],
			actual: $dependent->getErrors()
		);
	}//end testTheDependentRefusalKeepsCodeAndStatusOnly()
}//end class
