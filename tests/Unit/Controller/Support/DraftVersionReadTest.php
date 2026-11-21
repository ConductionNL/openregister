<?php

/**
 * Reading an object at a draft version.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\DraftVersionRead;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftService;
use PHPUnit\Framework\TestCase;

/**
 * The draft overlay behind `?version=<key>` (records-draft-versions 2.1).
 *
 * @covers \OCA\OpenRegister\Controller\Support\DraftVersionRead
 * @uses   \OCA\OpenRegister\Db\DraftVersion
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */
class DraftVersionReadTest extends TestCase {

	/**
	 * `main` and no version read the live object; any other key names a draft.
	 *
	 * @return void
	 */
	public function testMainAndNothingAreTheLiveObject(): void {
		self::assertFalse(DraftVersionRead::namesADraft(version: ''));
		self::assertFalse(DraftVersionRead::namesADraft(version: DraftService::MAIN));
		self::assertTrue(DraftVersionRead::namesADraft(version: 'update-1'));
	}//end testMainAndNothingAreTheLiveObject()

	/**
	 * The draft's delta is merged into a copy; the live object is not touched.
	 *
	 * @return void
	 */
	public function testTheDeltaIsMergedIntoACopy(): void {
		$object = self::object();
		$draft  = self::draft();
		$drafts = $this->createMock(DraftService::class);
		$drafts->expects(self::once())->method('find')->with($object, self::isInstanceOf(Schema::class), 'update-1', 'alice')->willReturn($draft);
		$drafts->method('merged')->with($object, $draft)->willReturn(['title' => 'New title', 'amount' => 10]);

		$read = DraftVersionRead::of(drafts: $drafts, object: $object, schema: new Schema(), key: 'update-1', caller: 'alice');

		self::assertNotNull($read);
		self::assertNotSame($object, $read->entity);
		self::assertSame('New title', $read->entity->getObject()['title']);
		self::assertSame('Old title', $object->getObject()['title'], 'the live object is unchanged');
	}//end testTheDeltaIsMergedIntoACopy()

	/**
	 * A draft the caller may not see, or that does not exist, reads as nothing.
	 *
	 * @return void
	 */
	public function testAnInvisibleDraftReadsAsNothing(): void {
		$drafts = $this->createMock(DraftService::class);
		$drafts->method('find')->willReturn(null);
		$drafts->expects(self::never())->method('merged');

		self::assertNull(DraftVersionRead::of(drafts: $drafts, object: self::object(), schema: new Schema(), key: 'update-1', caller: 'bob'));
	}//end testAnInvisibleDraftReadsAsNothing()

	/**
	 * The rendered object carries `_version` with the key, the name and the base version, beside its own @self.
	 *
	 * @return void
	 */
	public function testTheRenderedObjectIsStampedWithItsVersion(): void {
		$drafts = $this->createMock(DraftService::class);
		$drafts->method('find')->willReturn(self::draft());
		$drafts->method('merged')->willReturn(['title' => 'New title']);

		$read = DraftVersionRead::of(drafts: $drafts, object: self::object(), schema: new Schema(), key: 'update-1', caller: 'alice');

		self::assertSame(
			['title' => 'New title', '@self' => ['id' => 'obj-1', '_version' => ['key' => 'update-1', 'name' => 'Price update', 'base' => '1.0.3']]],
			$read->stamped(rendered: ['title' => 'New title', '@self' => ['id' => 'obj-1']])
		);
		self::assertSame(['key' => 'update-1', 'name' => 'Price update', 'base' => '1.0.3'], $read->stamped(rendered: [])['@self']['_version']);
	}//end testTheRenderedObjectIsStampedWithItsVersion()

	/**
	 * A live object.
	 *
	 * @return ObjectEntity
	 */
	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setObject(['title' => 'Old title', 'amount' => 10]);

		return $object;
	}//end object()

	/**
	 * A draft of it.
	 *
	 * @return DraftVersion
	 */
	private static function draft(): DraftVersion {
		$draft = new DraftVersion();
		$draft->setDraftKey('update-1');
		$draft->setName('Price update');
		$draft->setBaseVersion('1.0.3');
		$draft->setDelta(['title' => 'New title']);

		return $draft;
	}//end draft()
}//end class
