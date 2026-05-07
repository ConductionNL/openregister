<?php

/**
 * FileMapperGetFileIdsForObjectsTest
 *
 * Unit tests covering the input-validation paths of FileMapper::getFileIdsForObjects().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FileMapper;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileMapper::getFileIdsForObjects().
 *
 * Covers the input-validation paths that can be exercised without a live database
 * connection. The DB-touching paths (UUID -> folder lookup and folder -> children
 * fetch) are exercised by the MagicMapper / postman integration test suites; this
 * unit test focuses on the early-return behavior that defines the API contract.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @see openspec/changes/opt-in-files-extend/specs/files-render-extension/spec.md
 */
class FileMapperGetFileIdsForObjectsTest extends TestCase
{

    /**
     * Mocked database connection. Configured in setUp().
     *
     * @var IDBConnection&MockObject
     */
    private IDBConnection&MockObject $db;

    /**
     * Mocked URL generator. Configured in setUp().
     *
     * @var IURLGenerator&MockObject
     */
    private IURLGenerator&MockObject $urlGenerator;

    /**
     * The FileMapper instance under test. Constructed in setUp().
     *
     * @var FileMapper
     */
    private FileMapper $mapper;

    /**
     * Build mocked dependencies and the FileMapper instance under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db           = $this->createMock(originalClassName: IDBConnection::class);
        $this->urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
        $this->mapper       = new FileMapper(db: $this->db, urlGenerator: $this->urlGenerator);

    }//end setUp()

    /**
     * Empty input must short-circuit before any DB query is issued.
     *
     * @return void
     */
    public function testEmptyInputReturnsEmptyArray(): void
    {
        // No DB queries should be issued for empty input.
        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertSame(expected: [], actual: $this->mapper->getFileIdsForObjects(uuids: []));

    }//end testEmptyInputReturnsEmptyArray()

    /**
     * Input containing only invalid UUIDs (non-strings, empty strings) is filtered
     * out before any DB query is issued, returning an empty result.
     *
     * @return void
     */
    public function testInputOfOnlyInvalidUuidsReturnsEmptyArray(): void
    {
        // Non-string and empty-string values are filtered out before any DB call.
        $this->db->expects($this->never())->method('getQueryBuilder');

        // Deliberate off-contract input — exercises the `is_string` guard on
        // every non-string path (int, null, bool) plus the empty-string path.
        // The method's docblock declares `string[]`, so static analysis would
        // otherwise flag this; the guard exists exactly to defend against
        // sloppy callers, and that defence deserves coverage.
        /** @psalm-suppress InvalidArgument */
        $result = $this->mapper->getFileIdsForObjects(uuids: ['', 0, null, false]);
        $this->assertSame(expected: [], actual: $result);

    }//end testInputOfOnlyInvalidUuidsReturnsEmptyArray()
}//end class
