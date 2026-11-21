<?php

/**
 * Characterisation tests for how BulkJobService reads the kind of a selection.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\BulkJob;

use InvalidArgumentException;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins selectionTypeOf(): ids, query, both, neither, and a key that is present
 * but not an array.
 */
class BulkJobSelectionTypeTest extends TestCase {

	/**
	 * Call the private selectionTypeOf() on an instance built without its collaborators.
	 *
	 * @param array<string, mixed> $selection The selection.
	 *
	 * @return string The selection type.
	 */
	private function selectionTypeOf(array $selection): string {
		$class = new ReflectionClass(BulkJobService::class);
		$method = $class->getMethod('selectionTypeOf');

		return $method->invoke($class->newInstanceWithoutConstructor(), $selection);
	}

	public function testAnIdsListIsAnIdsSelection(): void {
		$this->assertSame(BulkJob::SELECTION_IDS, $this->selectionTypeOf(['ids' => ['a'], 'query' => 'not-an-array']));
	}

	public function testAQueryObjectIsAQuerySelection(): void {
		$this->assertSame(BulkJob::SELECTION_QUERY, $this->selectionTypeOf(['ids' => null, 'query' => []]));
	}

	public function testBothIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A selection is either a list of ids or a query, never both. Send one of the two.');

		$this->selectionTypeOf(['ids' => [], 'query' => ['q' => 1]]);
	}

	public function testNeitherIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A selection needs either an ids array or a query object, so the job can say which of the two it holds.');

		$this->selectionTypeOf(['ids' => 'a,b', 'query' => 7]);
	}
}//end class
