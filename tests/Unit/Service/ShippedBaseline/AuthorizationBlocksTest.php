<?php

/**
 * Tests for AuthorizationBlocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Service\ShippedBaseline\AuthorizationBlocks;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use PHPUnit\Framework\TestCase;

/**
 * An authorization block is settled as one unit: never merged path by path.
 *
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\AuthorizationBlocks
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts
 */
class AuthorizationBlocksTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @return AuthorizationBlocks
	 */
	private function blocks(): AuthorizationBlocks {
		return new AuthorizationBlocks(parts: new DescriptorParts());
	}//end blocks()

	/**
	 * The root of a path is the schema block, a property block, or none.
	 *
	 * @return void
	 */
	public function testRootOf(): void {
		$this->assertSame('authorization', $this->blocks()->rootOf(path: 'authorization.read.0'));
		$this->assertSame('properties.bsn.authorization', $this->blocks()->rootOf(path: 'properties.bsn.authorization.read.0'));
		$this->assertNull($this->blocks()->rootOf(path: 'properties.bsn.type'));
		$this->assertNull($this->blocks()->rootOf(path: 'properties.bsn'));
		$this->assertNull($this->blocks()->rootOf(path: 'title'));
	}//end testRootOf()

	/**
	 * Roots are collected across definitions without duplicates.
	 *
	 * @return void
	 */
	public function testRootsAcrossDefinitions(): void {
		$this->assertSame(
			['authorization', 'properties.bsn.authorization', 'properties.iban.authorization'],
			$this->blocks()->roots(
				['authorization' => [], 'properties' => ['bsn' => ['authorization' => null], 'name' => ['type' => 'string'], 'raw' => 'x']],
				['properties' => 'not-a-map'],
				['authorization' => ['read' => []], 'properties' => ['iban' => ['authorization' => []], 'bsn' => ['authorization' => []]]]
			)
		);
	}//end testRootsAcrossDefinitions()

	/**
	 * Member states roll up per block; a decided block is applied upstream and remembers
	 * whether it replaced a local change; unchanged blocks and non-authorization paths vanish.
	 *
	 * @return void
	 */
	public function testSettleRollsUpAndAppliesDecisions(): void {
		$settled = $this->blocks()->settle(
			states: [
				'authorization.read.0'                  => 'local',
				'authorization.read.1'                  => 'upstream',
				'properties.bsn.authorization.read.0'   => 'local',
				'properties.iban.authorization.read.0'  => 'upstream',
				'properties.name.authorization.read.0'  => 'same',
				'properties.notes.authorization.read.0' => 'both',
				'title'                                 => 'both',
			],
			decisions: ['properties.bsn.authorization', 'properties.iban.authorization']
		);

		$this->assertSame(
			[
				'authorization'                 => ['state' => 'both', 'decided' => false, 'localMoved' => true],
				'properties.bsn.authorization'  => ['state' => 'upstream', 'decided' => true, 'localMoved' => true],
				'properties.iban.authorization' => ['state' => 'upstream', 'decided' => true, 'localMoved' => false],
				'properties.notes.authorization' => ['state' => 'both', 'decided' => false, 'localMoved' => true],
			],
			$settled
		);
	}//end testSettleRollsUpAndAppliesDecisions()

	/**
	 * The outcome names applied and replaced blocks, and each conflict with both sides as trees.
	 *
	 * @return void
	 */
	public function testOutcomeNamesAppliedReplacedAndConflicts(): void {
		$outcome = $this->blocks()->outcome(
			blocks: [
				'authorization'                => ['state' => 'both', 'decided' => false, 'localMoved' => true],
				'properties.bsn.authorization' => ['state' => 'upstream', 'decided' => true, 'localMoved' => true],
				'properties.iban.authorization' => ['state' => 'upstream', 'decided' => true, 'localMoved' => false],
			],
			sources: [
				'incoming' => ['authorization.read.0' => 'public', 'title' => 'x'],
				'live'     => ['authorizationish' => 'no'],
			]
		);

		$this->assertSame(['properties.bsn.authorization', 'properties.iban.authorization'], $outcome['applied']);
		$this->assertSame(['properties.bsn.authorization'], $outcome['replacedLocal']);
		$this->assertSame(
			[['path' => 'authorization', 'shipped' => ['read' => ['public']], 'live' => null, 'shippedPresent' => true, 'livePresent' => false]],
			$outcome['conflicts']
		);
	}//end testOutcomeNamesAppliedReplacedAndConflicts()
}//end class
