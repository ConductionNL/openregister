<?php

/**
 * A hierarchy run admits an inherited grant without overriding a direct one.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\HierarchyDescent;
use OCA\OpenRegister\Service\Rbac\HierarchyInheritanceRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\HierarchyInheritanceRun
 * @uses   \OCA\OpenRegister\Service\Rbac\HierarchyDescent
 */
class HierarchyInheritanceRunTest extends TestCase {

	private function inheritanceRun(array $granted = [], array $sources = []): HierarchyInheritanceRun {
		return new HierarchyInheritanceRun(
			['table' => 'oc_or_cases', 'parentColumn' => 'parent', 'maxDepth' => 3],
			new HierarchyDescent(seen: [], granted: $granted, sources: $sources)
		);
	}

	public function testARunStartsWithAnEmptyFrontierAtDepthZero(): void {
		$run = $this->inheritanceRun();

		$this->assertSame([], $run->frontier);
		$this->assertSame(0, $run->depth);
		$this->assertSame('oc_or_cases', $run->hierarchy['table']);
	}

	public function testAnAdmittedChildIsGrantedTheRootsMask(): void {
		$run = $this->inheritanceRun();
		$run->admit('child-1', ['mask' => 5, 'root' => 'root-1']);

		$this->assertSame(['child-1' => ['mask' => 5, 'root' => 'root-1']], $run->descent->seen);
		$this->assertSame(['child-1' => 5], $run->descent->granted);
		$this->assertSame(['child-1' => 'root-1'], $run->descent->sources);
	}

	public function testADirectGrantIsNeverOverwritten(): void {
		$run = $this->inheritanceRun(granted: ['child-1' => 15], sources: ['child-1' => 'direct']);
		$run->admit('child-1', ['mask' => 1, 'root' => 'root-1']);

		$this->assertSame(['child-1' => 15], $run->descent->granted);
		$this->assertSame(['child-1' => 'direct'], $run->descent->sources);
		$this->assertArrayHasKey('child-1', $run->descent->seen);
	}

	public function testAnEmptyMaskIsSeenButNotGranted(): void {
		$run = $this->inheritanceRun();
		$run->admit('child-1', ['mask' => 0, 'root' => 'root-1']);

		$this->assertSame(['child-1' => ['mask' => 0, 'root' => 'root-1']], $run->descent->seen);
		$this->assertSame([], $run->descent->granted);
		$this->assertSame([], $run->descent->sources);
	}

	public function testTheFirstInheritedGrantWins(): void {
		$run = $this->inheritanceRun();
		$run->admit('child-1', ['mask' => 3, 'root' => 'root-a']);
		$run->admit('child-1', ['mask' => 7, 'root' => 'root-b']);

		$this->assertSame(['child-1' => 3], $run->descent->granted);
		$this->assertSame(['child-1' => 'root-a'], $run->descent->sources);
		$this->assertSame('root-b', $run->descent->seen['child-1']['root']);
	}
}
