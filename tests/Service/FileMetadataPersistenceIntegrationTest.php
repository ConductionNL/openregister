<?php

/**
 * Integration test for the OR-side `openregister_files` metadata
 * persistence layer (file-actions foundation work).
 *
 * Proves the missing-table architectural gap is fixed:
 * - The table is created (Migration Version1Date20260502130000).
 * - `FileMapper::findByFileId()` returns null when no row exists.
 * - `FileMapper::findOrCreateByFileId()` creates lazily and round-trips.
 * - `FileMapper::setDescriptionForFile / setCategoryForFile / setLabelsForFile`
 *   persist and round-trip the field.
 * - `FileMapper::incrementDownloadCount()` is idempotent + monotonic.
 * - `FileMapper::setLockForFile()` round-trips lock fields.
 * - `FileMapper::findByFileIds()` does the bulk lookup in one round trip.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/file-actions/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use DateTime;
use OCA\OpenRegister\Db\File;
use OCA\OpenRegister\Db\FileMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class FileMetadataPersistenceIntegrationTest extends TestCase
{

    private FileMapper $fileMapper;

    /**
     * @var int[] File IDs created during this test, deleted on tearDown.
     */
    private array $createdFileIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileMapper = \OC::$server->get(FileMapper::class);
    }//end setUp()

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ($this->createdFileIds as $fileId) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_files')
                    ->where(
                        $qb->expr()->eq(
                            'file_id',
                            $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)
                        )
                    );
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        parent::tearDown();
    }//end tearDown()

    public function testFindByFileIdReturnsNullWhenAbsent(): void
    {
        $fileId = $this->randomFileId();
        $found  = $this->fileMapper->findByFileId(fileId: $fileId);
        $this->assertNull($found, 'no row exists yet for this fileId');
    }//end testFindByFileIdReturnsNullWhenAbsent()

    public function testFindOrCreateRoundTripsTheRow(): void
    {
        $fileId = $this->randomFileId();
        $this->createdFileIds[] = $fileId;

        $created = $this->fileMapper->findOrCreateByFileId(fileId: $fileId);
        $this->assertInstanceOf(File::class, $created);
        $this->assertSame($fileId, $created->getFileId());
        $this->assertSame(0, $created->getDownloadCount());

        $foundAgain = $this->fileMapper->findByFileId(fileId: $fileId);
        $this->assertNotNull($foundAgain);
        $this->assertSame(
            $created->getId(),
            $foundAgain->getId(),
            'subsequent find returns the same persisted row'
        );

        $createdAgain = $this->fileMapper->findOrCreateByFileId(fileId: $fileId);
        $this->assertSame(
            $created->getId(),
            $createdAgain->getId(),
            'findOrCreate is idempotent — second call returns the same row, not a new one'
        );
    }//end testFindOrCreateRoundTripsTheRow()

    public function testDescriptionCategoryLabelsRoundTrip(): void
    {
        $fileId = $this->randomFileId();
        $this->createdFileIds[] = $fileId;

        $this->fileMapper->setDescriptionForFile(fileId: $fileId, description: 'Annual financial report Q4 2025');
        $this->fileMapper->setCategoryForFile(fileId: $fileId, category: 'financial');
        $this->fileMapper->setLabelsForFile(fileId: $fileId, labels: ['urgent', 'public', 'q4-2025']);

        $stored = $this->fileMapper->findByFileId(fileId: $fileId);
        $this->assertNotNull($stored);
        $this->assertSame('Annual financial report Q4 2025', $stored->getDescription());
        $this->assertSame('financial', $stored->getCategory());
        $this->assertSame(['urgent', 'public', 'q4-2025'], $stored->getLabels());
    }//end testDescriptionCategoryLabelsRoundTrip()

    public function testIncrementDownloadCountIsMonotonic(): void
    {
        $fileId = $this->randomFileId();
        $this->createdFileIds[] = $fileId;

        $first  = $this->fileMapper->incrementDownloadCount(fileId: $fileId);
        $second = $this->fileMapper->incrementDownloadCount(fileId: $fileId);
        $third  = $this->fileMapper->incrementDownloadCount(fileId: $fileId);

        $this->assertSame(1, $first->getDownloadCount());
        $this->assertSame(2, $second->getDownloadCount());
        $this->assertSame(3, $third->getDownloadCount());
    }//end testIncrementDownloadCountIsMonotonic()

    public function testSetLockRoundTripsAndReleases(): void
    {
        $fileId = $this->randomFileId();
        $this->createdFileIds[] = $fileId;

        $now     = new DateTime('2026-05-02T14:00:00+00:00');
        $expires = new DateTime('2026-05-02T14:30:00+00:00');

        $locked = $this->fileMapper->setLockForFile(
            fileId: $fileId,
            lockedBy: 'behandelaar-1',
            lockedAt: $now,
            lockExpires: $expires
        );

        $this->assertSame('behandelaar-1', $locked->getLockedBy());
        $this->assertEquals(
            $now->format('c'),
            $locked->getLockedAt()->format('c'),
            'lockedAt round-trips through datetime conversion'
        );
        $this->assertEquals(
            $expires->format('c'),
            $locked->getLockExpires()->format('c'),
            'lockExpires round-trips through datetime conversion'
        );

        // Release the lock by passing null on each lock field.
        $released = $this->fileMapper->setLockForFile(
            fileId: $fileId,
            lockedBy: null,
            lockedAt: null,
            lockExpires: null
        );
        $this->assertNull($released->getLockedBy());
        $this->assertNull($released->getLockedAt());
        $this->assertNull($released->getLockExpires());
    }//end testSetLockRoundTripsAndReleases()

    public function testFindByFileIdsBulkLookup(): void
    {
        $fileIds = [
            $this->randomFileId(),
            $this->randomFileId(),
            $this->randomFileId(),
        ];

        foreach ($fileIds as $fileId) {
            $this->createdFileIds[] = $fileId;
            $this->fileMapper->setCategoryForFile(fileId: $fileId, category: 'bulk-test-'.$fileId);
        }

        // Add an extra fileId with no row to confirm it's just absent from the result.
        $absent = $this->randomFileId();

        $map = $this->fileMapper->findByFileIds(fileIds: array_merge($fileIds, [$absent]));

        $this->assertCount(3, $map, 'bulk lookup returns only existing rows');
        foreach ($fileIds as $fileId) {
            $this->assertArrayHasKey($fileId, $map);
            $this->assertSame('bulk-test-'.$fileId, $map[$fileId]->getCategory());
        }

        $this->assertArrayNotHasKey($absent, $map);
    }//end testFindByFileIdsBulkLookup()

    private function randomFileId(): int
    {
        return random_int(900000000, 999999999);
    }//end randomFileId()
}//end class
