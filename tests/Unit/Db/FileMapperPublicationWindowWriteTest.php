<?php

/**
 * Characterisation of FileMapper::setPublicationWindowForFile().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Files\Db\File;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Both bounds land on the file row in one update, nulls included.
 */
class FileMapperPublicationWindowWriteTest extends TestCase {

	/**
	 * The row the mapper updated, or null when it did not update.
	 *
	 * @var File|null
	 */
	private ?File $updated = null;

	/**
	 * A mapper whose lookup returns the given row and whose update records it.
	 *
	 * @param File $row The stored row.
	 *
	 * @return FileMapper
	 */
	private function mapper(File $row): FileMapper {
		$mapper = $this->getMockBuilder(FileMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findOrCreateByFileId', 'update'])
			->getMock();
		$mapper->expects($this->once())->method('findOrCreateByFileId')->with(42)->willReturn($row);
		$mapper->method('update')->willReturnCallback(function (Entity $entity): Entity {
			$this->updated = $entity;
			return $entity;
		});
		return $mapper;
	}//end mapper()

	public function testBothBoundsAreWrittenTogether(): void {
		$row = new File();
		$from = new DateTime('2026-01-01T00:00:00+00:00');
		$until = new DateTime('2026-02-01T00:00:00+00:00');

		$result = $this->mapper($row)->setPublicationWindowForFile(42, $from, $until);

		$this->assertSame($row, $result);
		$this->assertSame($row, $this->updated);
		$this->assertSame($from, $row->getPublished());
		$this->assertSame($until, $row->getDepublished());
		$this->assertInstanceOf(DateTime::class, $row->getUpdated());
	}//end testBothBoundsAreWrittenTogether()

	public function testNullBoundsClearAnEarlierWindow(): void {
		$row = new File();
		$row->setPublished(new DateTime('2026-01-01T00:00:00+00:00'));
		$row->setDepublished(new DateTime('2026-02-01T00:00:00+00:00'));

		$this->mapper($row)->setPublicationWindowForFile(42, null, null);

		$this->assertNull($row->getPublished());
		$this->assertNull($row->getDepublished());
		$this->assertSame($row, $this->updated);
	}//end testNullBoundsClearAnEarlierWindow()
}//end class
