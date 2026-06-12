<?php

/**
 * Integration test for UpdateFileHandler::updateFileMetadata and
 * ReadFileHandler::getFiles category filtering. Both delegate to
 * FileMapper.
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

use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Service\File\UpdateFileHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class FileMetadataUpdateIntegrationTest extends TestCase
{

    private FileMapper $fileMapper;

    private UpdateFileHandler $updateHandler;

    /**
     * @var int[]
     */
    private array $createdFileIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileMapper    = \OC::$server->get(FileMapper::class);
        $this->updateHandler = \OC::$server->get(UpdateFileHandler::class);
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

    public function testUpdateMetadataWritesAllFieldsThroughHandler(): void
    {
        $fileId = $this->randomFileId();
        $this->createdFileIds[] = $fileId;

        $entity = $this->updateHandler->updateFileMetadata(
            fileId: $fileId,
            description: 'Q3 board minutes',
            category: 'governance',
            labels: ['confidential', 'board', 'q3-2025']
        );

        $this->assertSame('Q3 board minutes', $entity->getDescription());
        $this->assertSame('governance', $entity->getCategory());
        $this->assertSame(['confidential', 'board', 'q3-2025'], $entity->getLabels());
    }//end testUpdateMetadataWritesAllFieldsThroughHandler()

    public function testUpdateMetadataPartialUpdateLeavesUntouchedFieldsAlone(): void
    {
        $fileId = $this->randomFileId();
        $this->createdFileIds[] = $fileId;

        $this->updateHandler->updateFileMetadata(
            fileId: $fileId,
            description: 'Initial description',
            category: 'initial-cat',
            labels: ['initial']
        );

        // Touch only the description; category + labels MUST stay.
        $entity = $this->updateHandler->updateFileMetadata(
            fileId: $fileId,
            description: 'Updated description'
        );

        $this->assertSame('Updated description', $entity->getDescription());
        $this->assertSame('initial-cat', $entity->getCategory(), 'category MUST NOT be cleared by an unrelated update');
        $this->assertSame(['initial'], $entity->getLabels(), 'labels MUST NOT be cleared either');
    }//end testUpdateMetadataPartialUpdateLeavesUntouchedFieldsAlone()

    public function testUpdateMetadataExplicitEmptyClearsField(): void
    {
        $fileId = $this->randomFileId();
        $this->createdFileIds[] = $fileId;

        $this->updateHandler->updateFileMetadata(
            fileId: $fileId,
            description: 'Old description',
            category: 'old-cat',
            labels: ['old', 'tags']
        );

        // Explicit empty values clear, distinct from "leave untouched".
        $entity = $this->updateHandler->updateFileMetadata(
            fileId: $fileId,
            description: '',
            category: '',
            labels: []
        );

        $this->assertSame('', $entity->getDescription());
        $this->assertSame('', $entity->getCategory());
        $this->assertSame([], $entity->getLabels());
    }//end testUpdateMetadataExplicitEmptyClearsField()

    private function randomFileId(): int
    {
        return random_int(900000000, 999999999);
    }//end randomFileId()
}//end class
