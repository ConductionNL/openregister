<?php

/**
 * ImportPreviewMapperTest: reading, creating and persisting import previews.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ImportPreviewMapper
 * @uses   \OCA\OpenRegister\Db\ImportPreview
 * @uses   \OCA\OpenRegister\Db\QueryPage
 */
class ImportPreviewMapperTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper on a recording connection.
	 *
	 * @return ImportPreviewMapper
	 */
	private function mapper(): ImportPreviewMapper {
		return new ImportPreviewMapper($this->recordingConnection());
	}//end mapper()

	public function testFindReadsOnePreviewById(): void {
		$this->rowSets = [[['id' => 5, 'uuid' => 'p5', 'state' => 'ready']]];

		$preview = $this->mapper()->find(5);

		$this->assertSame('p5', $preview->getUuid());
		$this->assertSame(['select(*)', 'from(openregister_import_previews)', 'where(eq(id, 5))', 'executeQuery'], $this->queries[0]);
	}//end testFindReadsOnePreviewById()

	public function testFindOfAnUnknownIdThrows(): void {
		$this->rowSets = [[]];

		$this->expectException(DoesNotExistException::class);
		$this->mapper()->find(404);
	}//end testFindOfAnUnknownIdThrows()

	public function testFindByUuidFiltersOnTheUuid(): void {
		$this->rowSets = [[['id' => 6, 'uuid' => 'p6']]];

		$this->assertSame(6, $this->mapper()->findByUuid('p6')->getId());
		$this->assertContains("where(eq(uuid, 'p6'))", $this->queries[0]);
	}//end testFindByUuidFiltersOnTheUuid()

	public function testFindByActorListsTheActorsPreviewsNewestFirstWithoutPaging(): void {
		$this->rowSets = [[['id' => 9, 'created_by' => 'ann'], ['id' => 4, 'created_by' => 'ann']]];

		$previews = $this->mapper()->findByActor('ann');

		$this->assertSame([9, 4], array_map(static fn (ImportPreview $p): int => $p->getId(), $previews));
		$this->assertSame(['select(*)', 'from(openregister_import_previews)', "where(eq(created_by, 'ann'))", 'orderBy(id, DESC)', 'executeQuery'], $this->queries[0]);
	}//end testFindByActorListsTheActorsPreviewsNewestFirstWithoutPaging()

	public function testFindByActorNarrowsByStateAndPages(): void {
		$this->mapper()->findByActor('ann', new ListQuery(limit: 10, offset: 20, filters: ['state' => 'applied']));

		$this->assertSame(
			["andWhere(eq(state, 'applied'))", 'setMaxResults(10)', 'setFirstResult(20)', 'executeQuery'],
			array_slice($this->queries[0], 4)
		);
	}//end testFindByActorNarrowsByStateAndPages()

	public function testCreateFromArraySetsKnownFieldsIgnoresUnknownOnesAndStampsTimes(): void {
		$this->lastInsertId = 31;

		$preview = $this->mapper()->createFromArray(['uuid' => 'given', 'state' => 'pending', 'createdBy' => 'ann', 'noSuchField' => 'x']);

		$this->assertSame(31, $preview->getId());
		$this->assertSame('given', $preview->getUuid());
		$this->assertSame('pending', $preview->getState());
		$this->assertInstanceOf(DateTime::class, $preview->getCreated());
		$this->assertSame($preview->getCreated(), $preview->getUpdated());
		$this->assertContains("setValue(uuid, 'given')", $this->queries[0]);
		$this->assertContains("setValue(created_by, 'ann')", $this->queries[0]);
		$this->assertSame([], array_filter($this->queries[0], static fn (string $call): bool => str_contains($call, 'no_such_field')));
	}//end testCreateFromArraySetsKnownFieldsIgnoresUnknownOnesAndStampsTimes()

	public function testCreateFromArrayMintsAUuidWhenNoneIsGiven(): void {
		$preview = $this->mapper()->createFromArray(['state' => 'pending']);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $preview->getUuid());
	}//end testCreateFromArrayMintsAUuidWhenNoneIsGiven()

	public function testPersistStampsTheUpdateTimeAndUpdatesTheRow(): void {
		$preview = new ImportPreview();
		$preview->setId(12);
		$preview->resetUpdatedFields();
		$preview->setState('applied');

		$saved = $this->mapper()->persist($preview);

		$this->assertSame($preview, $saved);
		$this->assertInstanceOf(DateTime::class, $saved->getUpdated());
		$this->assertContains('update(openregister_import_previews)', $this->queries[0]);
		$this->assertContains("set(state, 'applied')", $this->queries[0]);
		$this->assertContains('where(eq(id, 12))', $this->queries[0]);
	}//end testPersistStampsTheUpdateTimeAndUpdatesTheRow()
}//end class
