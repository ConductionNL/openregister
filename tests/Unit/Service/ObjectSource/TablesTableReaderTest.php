<?php

/**
 * Unit tests for TablesTableReader.
 *
 * The Tables app's `OCA\Tables\Service\*` classes are not loadable under the CI
 * runner, so these tests cover the fail-closed contract that is observable
 * without Tables: availability is false, and every read degrades to an empty /
 * null result rather than a fatal. The live extraction of real Tables entities is
 * verified once the Tables app is installed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Test class for TablesTableReader.
 */
class TablesTableReaderTest extends TestCase
{

    /**
     * Build a reader with a configurable app-enabled state.
     *
     * @param bool $appThere Whether the Tables app reports enabled.
     *
     * @return TablesTableReader The reader under test.
     */
    private function reader(bool $appThere=true): TablesTableReader
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn($appThere);
        $container = $this->createMock(ContainerInterface::class);

        return new TablesTableReader($appManager, $container, new NullLogger());
    }//end reader()

    /**
     * isAvailable() is false when the Tables service classes are not loadable,
     * even when the app reports enabled (the CI runner has no Tables app).
     *
     * @return void
     */
    public function testIsAvailableFalseWithoutTablesClasses(): void
    {
        if (class_exists('OCA\\Tables\\Service\\RowService') === true) {
            $this->markTestSkipped('Tables app is present; availability is covered by live-verify.');
        }

        $this->assertFalse($this->reader(true)->isAvailable());
        $this->assertFalse($this->reader(false)->isAvailable());
    }//end testIsAvailableFalseWithoutTablesClasses()

    /**
     * Every read fails closed to empty/null/0 when Tables is absent.
     *
     * @return void
     */
    public function testReadsFailClosed(): void
    {
        if (class_exists('OCA\\Tables\\Service\\RowService') === true) {
            $this->markTestSkipped('Tables app is present; mapping is covered by live-verify.');
        }

        $reader = $this->reader(true);

        $this->assertSame([], $reader->listTables(userId: 'alice'));
        $this->assertSame([], $reader->listColumns(tableId: 5, userId: 'alice'));
        $this->assertSame([], $reader->findRowsByTable(tableId: 5, userId: 'alice'));
        $this->assertSame([], $reader->findRowsByView(viewId: 9, userId: 'alice'));
        $this->assertSame([], $reader->collectTableDescriptors(userIds: ['alice']));
        $this->assertNull($reader->findRow(rowId: 1, userId: 'alice'));
        $this->assertSame(0, $reader->countRows(id: 5, userId: 'alice'));
    }//end testReadsFailClosed()
}//end class
