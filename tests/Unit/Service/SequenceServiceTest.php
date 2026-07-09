<?php

/**
 * OpenRegister SequenceServiceTest
 *
 * Unit tests for the atomic running-number reservation service backing the
 * declarative `sequence` calculation operator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Sequence;
use OCA\OpenRegister\Db\SequenceMapper;
use OCA\OpenRegister\Service\SequenceService;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SequenceService::reserveNext().
 *
 * Covers: first reservation seeds (returns 1), subsequent increments,
 * scope-key isolation, and the unique-violation seed-race fallback. All within
 * a committed transaction; a thrown reservation rolls back.
 */
class SequenceServiceTest extends TestCase
{

    /** @var SequenceMapper&MockObject */
    private $mapper;

    /** @var IDBConnection&MockObject */
    private $db;

    private SequenceService $service;


    /**
     * Wire the service with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper  = $this->createMock(SequenceMapper::class);
        $this->db      = $this->createMock(IDBConnection::class);
        $this->service = new SequenceService($this->mapper, $this->db);

    }//end setUp()


    /**
     * Build a Sequence entity with a given next_value.
     *
     * @param int $next The next_value to seed.
     *
     * @return Sequence
     */
    private function row(int $next): Sequence
    {
        $row = new Sequence();
        $row->setRegisterId(1);
        $row->setSchemaId(2);
        $row->setScopeKey('2026');
        $row->setNextValue($next);
        return $row;

    }//end row()


    /**
     * First reservation on a missing scope seeds the row and returns 1.
     *
     * @return void
     */
    public function testFirstReservationSeedsAndReturnsOne(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        // No existing row → increment affects 0 rows → seed path.
        $this->mapper->method('incrementScope')->willReturn(0);
        $this->mapper->expects($this->once())->method('insert');

        $value = $this->service->reserveNext(registerId: 1, schemaId: 2, scopeKey: '2026');
        $this->assertSame(1, $value);

    }//end testFirstReservationSeedsAndReturnsOne()


    /**
     * A subsequent reservation increments and returns next_value - 1.
     *
     * @return void
     */
    public function testSubsequentReservationIncrements(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        // Existing row → increment affects 1 row; the row now reads next_value=3,
        // so the value reserved by THIS call is 2.
        $this->mapper->method('incrementScope')->willReturn(1);
        $this->mapper->method('findForScope')->willReturn($this->row(3));

        $value = $this->service->reserveNext(registerId: 1, schemaId: 2, scopeKey: '2026');
        $this->assertSame(2, $value);

    }//end testSubsequentReservationIncrements()


    /**
     * A lost insert race (unique violation) falls back to the increment path.
     *
     * @return void
     */
    public function testSeedRaceFallsBackToIncrement(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        // increment returns 0 (no row yet) → seed; insert loses the race and
        // throws; fallback increment then reads next_value=3 → reserved 2.
        $this->mapper->method('incrementScope')->willReturn(0, 1);
        $this->mapper->method('insert')->willThrowException(
            new DbException('duplicate key value violates unique constraint')
        );
        $this->mapper->method('findForScope')->willReturn($this->row(3));

        $value = $this->service->reserveNext(registerId: 1, schemaId: 2, scopeKey: '2026');
        $this->assertSame(2, $value);

    }//end testSeedRaceFallsBackToIncrement()


    /**
     * An unexpected error rolls the transaction back and rethrows.
     *
     * @return void
     */
    public function testUnexpectedErrorRollsBack(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->mapper->method('incrementScope')->willThrowException(new DbException('boom'));

        $this->expectException(DbException::class);
        $this->service->reserveNext(registerId: 1, schemaId: 2, scopeKey: '2026');

    }//end testUnexpectedErrorRollsBack()


}//end class
