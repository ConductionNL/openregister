<?php

/**
 * EntityRelationMapper::linkDecision golden tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\DecisionConflictException;
use OCP\AppFramework\Db\QBMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The compare-and-swap of a guarded decision link, allowed and refused.
 */
class EntityRelationMapperLinkDecisionGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper over a recording connection, with update() stubbed.
	 *
	 * @return EntityRelationMapper&MockObject
	 */
	private function mapper(): EntityRelationMapper&MockObject {
		$mapper = $this->getMockBuilder(EntityRelationMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update'])
			->getMock();
		$mapper->method('update')->willReturnArgument(0);
		(new ReflectionProperty(QBMapper::class, 'db'))->setValue($mapper, $this->recordingDb());
		(new ReflectionProperty(QBMapper::class, 'tableName'))->setValue($mapper, 'openregister_entity_relations');

		return $mapper;
	}//end mapper()

	/**
	 * A relation with id 9.
	 *
	 * @return EntityRelation
	 */
	private function relation(): EntityRelation {
		$relation = new EntityRelation();
		$relation->setId(9);

		return $relation;
	}//end relation()

	/**
	 * Unguarded: no compare-and-swap query, the uuid is stored.
	 *
	 * @return void
	 */
	public function testUnguardedLinkWritesWithoutACheck(): void {
		$stored = $this->mapper()->linkDecision($this->relation(), 'd-1');

		$this->assertSame('d-1', $stored->getDecisionUuid());
		$this->assertSame([], $this->queries);
	}//end testUnguardedLinkWritesWithoutACheck()

	/**
	 * Guarded against an expected link: allowed when one row matched.
	 *
	 * @return void
	 */
	public function testGuardedLinkSwapsWhileTheExpectedLinkHolds(): void {
		$stored = $this->mapper()->linkDecision($this->relation(), 'd-2', 'd-1', true);

		$this->assertSame('d-2', $stored->getDecisionUuid());
		$this->assertSame('update(openregister_entity_relations) set(decision_uuid, :p0) where(eq(id, :p1)) andWhere(eq(decision_uuid, :p2)) executeStatement()', $this->sql());
		$this->assertSame(['d-2', 9, 'd-1'], [$this->bound(':p0'), $this->bound(':p1'), $this->bound(':p2')]);
	}//end testGuardedLinkSwapsWhileTheExpectedLinkHolds()

	/**
	 * Guarded without an expected link: matches a null or empty stored link.
	 *
	 * @return void
	 */
	public function testGuardedFirstLinkRequiresNoStoredLink(): void {
		$this->mapper()->linkDecision($this->relation(), 'd-1', null, true);

		$this->assertSame('update(openregister_entity_relations) set(decision_uuid, :p0) where(eq(id, :p1)) andWhere(orX(isNull(decision_uuid), eq(decision_uuid, :p2))) executeStatement()', $this->sql());
	}//end testGuardedFirstLinkRequiresNoStoredLink()

	/**
	 * Guarded and no row matched: refused, and the relation keeps its link.
	 *
	 * @return void
	 */
	public function testGuardedLinkIsRefusedWhenTheLinkChanged(): void {
		$this->affected = 0;
		$relation       = $this->relation();

		try {
			$this->mapper()->linkDecision($relation, 'd-2', 'd-1', true);
			$this->fail('A changed link must be refused.');
		} catch (DecisionConflictException $e) {
			$this->assertNull($relation->getDecisionUuid());
		}
	}//end testGuardedLinkIsRefusedWhenTheLinkChanged()
}//end class
