<?php

/**
 * Behaviour tests for SearchIndexSnapshotRestore: gaps, failed creates, dry runs and unreadable files.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Search;

use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;
use OCA\OpenRegister\Search\Service\Search\SearchIndexSnapshotRestore;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexSnapshotRestore
 * @uses   \OCA\OpenRegister\Search\Service\Search\SearchIndexPass
 */
class SearchIndexSnapshotRestoreBehaviourTest extends TestCase
{
    private string $path;

    /** @var SearchIndexMaintenance&MockObject */
    private SearchIndexMaintenance $maintenance;

    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    /** @var list<string> */
    private array $executed = [];

    protected function setUp(): void
    {
        $this->path        = (string) tempnam(sys_get_temp_dir(), 'or-restore-b-');
        $this->maintenance = $this->createMock(SearchIndexMaintenance::class);
        $this->db          = $this->createMock(IDBConnection::class);
        $this->maintenance->method('indexesFor')->willReturnCallback(
            static fn(string $table): array => match ($table) {
                't_1'   => ['idx_present' => 'CREATE INDEX idx_present ON t_1'],
                default => [],
            }
        );
        $this->db->method('executeStatement')->willReturnCallback(
            function (string $sql): int {
                $this->executed[] = $sql;
                if (str_contains($sql, 'idx_broken') === true) {
                    throw new RuntimeException('column "x" does not exist');
                }

                return 1;
            }
        );
    }//end setUp()

    protected function tearDown(): void
    {
        @unlink($this->path);
    }//end tearDown()

    private function restore(bool $apply): array
    {
        return (new SearchIndexSnapshotRestore($this->maintenance, $this->db))
            ->restore(path: $this->path, pass: new SearchIndexPass(apply: $apply, progress: null));
    }//end restore()

    public function testAFailedCreateIsReportedAndTheOtherGapsAreStillCreated(): void
    {
        file_put_contents(
            $this->path,
            (string) json_encode(
                [
                    'tables' => [
                        't_1' => ['idx_present' => 'CREATE INDEX idx_present ON t_1', 'idx_broken' => 'CREATE INDEX idx_broken ON t_1 (x)'],
                        't_2' => ['idx_new' => 'CREATE INDEX idx_new ON t_2'],
                    ],
                ]
            )
        );

        $report = $this->restore(apply: true);

        $this->assertSame(['CREATE INDEX idx_broken ON t_1 (x)', 'CREATE INDEX idx_new ON t_2'], $this->executed);
        $this->assertSame(
            [
                'path'     => $this->path,
                'missing'  => 2,
                'created'  => 1,
                'failed'   => 1,
                'failures' => [['table' => 't_1', 'index' => 'idx_broken', 'error' => 'column "x" does not exist']],
                'indexes'  => [['table' => 't_1', 'index' => 'idx_broken'], ['table' => 't_2', 'index' => 'idx_new']],
            ],
            $report
        );
    }//end testAFailedCreateIsReportedAndTheOtherGapsAreStillCreated()

    public function testADryRunListsGapsButRunsNothing(): void
    {
        file_put_contents($this->path, (string) json_encode(['tables' => ['t_2' => ['idx_new' => 'CREATE INDEX idx_new ON t_2']]]));

        $report = $this->restore(apply: false);

        $this->assertSame([], $this->executed);
        $this->assertSame(1, $report['missing']);
        $this->assertSame(0, $report['created']);
        $this->assertSame(0, $report['failed']);
    }//end testADryRunListsGapsButRunsNothing()

    public function testASnapshotWithNothingMissingCreatesNothing(): void
    {
        file_put_contents($this->path, (string) json_encode(['tables' => ['t_1' => ['idx_present' => 'CREATE INDEX idx_present ON t_1']]]));

        $report = $this->restore(apply: true);

        $this->assertSame([], $this->executed);
        $this->assertSame(['missing' => 0, 'created' => 0, 'failed' => 0], array_intersect_key($report, ['missing' => 1, 'created' => 1, 'failed' => 1]));
    }//end testASnapshotWithNothingMissingCreatesNothing()

    public function testAnUnreadableFileIsRefused(): void
    {
        unlink($this->path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not read the index snapshot at '{$this->path}'.");
        @$this->restore(apply: true);
    }//end testAnUnreadableFileIsRefused()

    public function testAFileThatIsNotJsonIsRefused(): void
    {
        file_put_contents($this->path, 'not json at all');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'{$this->path}' is not an index snapshot this version can read.");
        $this->restore(apply: true);
    }//end testAFileThatIsNotJsonIsRefused()
}//end class
