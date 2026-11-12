<?php

/**
 * FileMapper's own metadata row per Nextcloud file — found by file id or
 * created with zero downloads, its description, category, labels and
 * download count written through it — and the filecache counts and sizes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use OCA\OpenRegister\Files\Db\File;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * FileMapper metadata-row and filecache aggregate queries.
 *
 * @covers \OCA\OpenRegister\Files\Db\FileMapper
 * @uses \OCA\OpenRegister\Files\Db\File
 */
final class FileMapperMetadataRowTest extends TestCase {
	use RecordsQueries;

	private FileMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new FileMapper($this->recordingDb(), $this->createMock(ShareUrlBuilder::class));
	}//end setUp()

	public function testAMetadataRowIsFoundByItsFileId(): void {
		$this->results[] = [['id' => 3, 'file_id' => 77, 'download_count' => 4]];

		$file = $this->mapper->findByFileId(77);

		$this->assertSame('select(*) from(openregister_files) where(eq(file_id, :p0)) executeQuery()', $this->sql());
		$this->assertSame([77, IQueryBuilder::PARAM_INT], $this->params[':p0']);
		$this->assertSame(3, $file?->getId());
	}//end testAMetadataRowIsFoundByItsFileId()

	public function testAFileWithoutAMetadataRowIsNull(): void {
		$this->assertNull($this->mapper->findByFileId(78));
	}//end testAFileWithoutAMetadataRowIsNull()

	public function testAnExistingRowIsReturnedRatherThanCreated(): void {
		$this->results[] = [['id' => 3, 'file_id' => 77]];

		$file = $this->mapper->findOrCreateByFileId(77);

		$this->assertSame(3, $file->getId());
		$this->assertCount(1, $this->queries);
	}//end testAnExistingRowIsReturnedRatherThanCreated()

	public function testAMissingRowIsCreatedWithNoDownloads(): void {
		$file = $this->mapper->findOrCreateByFileId(90);

		$this->assertSame(90, $file->getFileId());
		$this->assertSame(0, $file->getDownloadCount());
		$this->assertInstanceOf(DateTime::class, $file->getCreated());
		$this->assertSame(42, $file->getId());
		$this->assertStringStartsWith('insert(openregister_files)', $this->sql(1));
	}//end testAMissingRowIsCreatedWithNoDownloads()

	public function testTheDescriptionCategoryAndLabelsAreWrittenToTheRow(): void {
		$this->results[] = [['id' => 3, 'file_id' => 77]];
		$this->results[] = [['id' => 3, 'file_id' => 77]];
		$this->results[] = [['id' => 3, 'file_id' => 77]];

		$described = $this->mapper->setDescriptionForFile(77, 'Signed contract');
		$this->assertSame('Signed contract', $described->getDescription());
		$this->assertInstanceOf(DateTime::class, $described->getUpdated());

		$this->assertSame('contracts', $this->mapper->setCategoryForFile(77, 'contracts')->getCategory());
		$this->assertSame(['signed', '2026'], $this->mapper->setLabelsForFile(77, ['signed', '2026'])->getLabels());

		$this->assertStringStartsWith('update(openregister_files)', $this->sql(1));
		$this->assertStringStartsWith('update(openregister_files)', $this->sql(3));
		$this->assertStringStartsWith('update(openregister_files)', $this->sql(5));
	}//end testTheDescriptionCategoryAndLabelsAreWrittenToTheRow()

	public function testADownloadIsCounted(): void {
		$this->results[] = [['id' => 3, 'file_id' => 77, 'download_count' => 4]];

		$this->assertSame(5, $this->mapper->incrementDownloadCount(77)->getDownloadCount());
	}//end testADownloadIsCounted()

	public function testTheFirstDownloadOfANewRowCountsOne(): void {
		$this->assertSame(1, $this->mapper->incrementDownloadCount(91)->getDownloadCount());
	}//end testTheFirstDownloadOfANewRowCountsOne()

	public function testNoFileIdsFindsNoRowsWithoutAQuery(): void {
		$this->assertSame([], $this->mapper->findByFileIds([]));
		$this->assertSame([], $this->queries);
	}//end testNoFileIdsFindsNoRowsWithoutAQuery()

	public function testRowsAreKeyedByTheirFileId(): void {
		$this->results[] = [['id' => 1, 'file_id' => 20], ['id' => 2, 'file_id' => 10]];

		$rows = $this->mapper->findByFileIds([10, 20]);

		$this->assertSame([20, 10], array_keys($rows));
		$this->assertSame(2, $rows[10]->getId());
		$this->assertSame([[10, 20], IQueryBuilder::PARAM_INT_ARRAY], $this->params[':p0']);
	}//end testRowsAreKeyedByTheirFileId()

	public function testAllNonDirectoryFilesAreCounted(): void {
		$this->results[] = [['count' => '12']];

		$this->assertSame(12, $this->mapper->countAllFiles());
		$this->assertSame(['httpd/unix-directory', IQueryBuilder::PARAM_STR], $this->params[':p0']);
		$this->assertStringContainsString('from(filecache, fc)', $this->sql());
		$this->assertStringContainsString('where(neq(mt.mimetype, :p0))', $this->sql());
	}//end testAllNonDirectoryFilesAreCounted()

	public function testAnEmptyCountIsZero(): void {
		$this->assertSame(0, $this->mapper->countAllFiles());
		$this->assertSame(0, $this->mapper->getTotalFilesSize());
	}//end testAnEmptyCountIsZero()

	public function testTheTotalSizeOfNonDirectoryFilesIsSummed(): void {
		$this->results[] = [['total_size' => '2048']];

		$this->assertSame(2048, $this->mapper->getTotalFilesSize());
		$this->assertSame(['httpd/unix-directory', IQueryBuilder::PARAM_STR], $this->params[':p0']);
	}//end testTheTotalSizeOfNonDirectoryFilesIsSummed()

	public function testSizesAreSummedOverDistinctIdsInChunksOfAThousand(): void {
		$this->results[] = [['total_size' => '100']];
		$this->results[] = [['total_size' => '5']];

		$ids = array_merge(range(1, 1001), [1, 2]);

		$this->assertSame(105, $this->mapper->sumSizesByFileIds($ids));
		$this->assertCount(2, $this->queries);
		$this->assertSame([range(1, 1000), IQueryBuilder::PARAM_INT_ARRAY], $this->params[':p0']);
		$this->assertSame([[1001], IQueryBuilder::PARAM_INT_ARRAY], $this->params[':p2']);
	}//end testSizesAreSummedOverDistinctIdsInChunksOfAThousand()

	public function testNoIdsSumToZeroWithoutAQuery(): void {
		$this->assertSame(0, $this->mapper->sumSizesByFileIds([]));
		$this->assertSame([], $this->queries);
	}//end testNoIdsSumToZeroWithoutAQuery()
}//end class
