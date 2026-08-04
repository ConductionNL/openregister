<?php

/**
 * Unit tests for TablesObjectSourceProvider.
 *
 * The Tables app is not installed under the CI runner, so all Tables access is
 * mocked at the {@see TablesTableReader} boundary (the sole class that names
 * `OCA\Tables\*`). These tests cover the observable provider contract: id,
 * enablement, RBAC fail-closed, row → object projection, find by rowId and by
 * derived UUID, count, native limit/offset pushdown, and the `config.viewId`
 * View binding.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for TablesObjectSourceProvider.
 */
class TablesObjectSourceProviderTest extends TestCase
{

    /**
     * The mocked Tables reader (the Tables-service boundary).
     *
     * @var TablesTableReader&MockObject
     */
    private $reader;

    /**
     * The mocked schema-sync service (relation-target lookups).
     *
     * @var TablesSchemaSyncService&MockObject
     */
    private $syncService;

    /**
     * The deriver (real — pure logic).
     *
     * @var TablesUuidDeriver
     */
    private TablesUuidDeriver $deriver;

    /**
     * Build collaborators before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->reader      = $this->createMock(TablesTableReader::class);
        $this->syncService = $this->createMock(TablesSchemaSyncService::class);
        $this->deriver     = new TablesUuidDeriver();
    }//end setUp()

    /**
     * Build a provider with a given login state.
     *
     * @param bool $loggedIn Whether a user is logged in.
     *
     * @return TablesObjectSourceProvider The provider under test.
     */
    private function provider(bool $loggedIn=true): TablesObjectSourceProvider
    {
        $userSession = $this->createMock(IUserSession::class);
        if ($loggedIn === true) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('alice');
            $userSession->method('getUser')->willReturn($user);
        } else {
            $userSession->method('getUser')->willReturn(null);
        }

        $columnMapper = new TablesColumnMapper($this->deriver, new NullLogger());

        return new TablesObjectSourceProvider(
            $this->reader,
            $columnMapper,
            $this->deriver,
            $this->syncService,
            $userSession,
            new NullLogger()
        );
    }//end provider()

    /**
     * The register/schema pair the provider is bound to.
     *
     * @return array{0: Register, 1: Schema} The register and schema.
     */
    private function binding(): array
    {
        $register = new Register();
        $register->setId(30);
        $schema = new Schema();
        $schema->setId(300);
        return [$register, $schema];
    }//end binding()

    /**
     * A minimal single-text-column table shape used by several tests.
     *
     * @return array<int, array<string, mixed>> The column descriptors.
     */
    private function columns(): array
    {
        return [['id' => 1, 'title' => 'Name', 'technicalName' => 'name', 'type' => 'text']];
    }//end columns()

    /**
     * getId() is the stable provider id.
     *
     * @return void
     */
    public function testGetId(): void
    {
        $this->assertSame('tables', $this->provider()->getId());
    }//end testGetId()

    /**
     * isEnabled() reflects the reader's availability.
     *
     * @return void
     */
    public function testIsEnabledReflectsReader(): void
    {
        $this->reader->method('isAvailable')->willReturn(true);
        $this->assertTrue($this->provider()->isEnabled());
    }//end testIsEnabledReflectsReader()

    /**
     * Reads fail closed to empty when no user is logged in.
     *
     * @return void
     */
    public function testFailsClosedWithoutUser(): void
    {
        [$register, $schema] = $this->binding();
        $provider = $this->provider(false);

        $this->assertSame([], $provider->findAll($register, $schema, [], ['tableId' => 5]));
        $this->assertSame(0, $provider->count($register, $schema, [], ['tableId' => 5]));
        $this->assertNull($provider->find($register, $schema, '1', ['tableId' => 5]));
    }//end testFailsClosedWithoutUser()

    /**
     * findAll pushes limit/offset natively to the reader and maps each row.
     *
     * @return void
     */
    public function testFindAllPushesPaginationAndMaps(): void
    {
        [$register, $schema] = $this->binding();

        $this->reader->method('listColumns')->willReturn($this->columns());
        $this->reader->expects($this->once())
            ->method('findRowsByTable')
            ->with(5, 'alice', 10, 20)
            ->willReturn([['id' => 42, 'cells' => [['columnId' => 1, 'value' => 'Swing']]]]);

        $objects = $this->provider()->findAll($register, $schema, ['limit' => 10, 'offset' => 20], ['tableId' => 5]);

        $this->assertCount(1, $objects);
        $this->assertSame('Swing', $objects[0]->getObject()['name']);
        $this->assertSame('42', $objects[0]->getObject()['id']);
        $this->assertSame($this->deriver->deriveObjectUuid(tableId: 5, rowId: 42), $objects[0]->getUuid());
    }//end testFindAllPushesPaginationAndMaps()

    /**
     * find() by numeric rowId returns the single mapped object.
     *
     * @return void
     */
    public function testFindByRowId(): void
    {
        [$register, $schema] = $this->binding();

        $this->reader->method('listColumns')->willReturn($this->columns());
        $this->reader->expects($this->once())
            ->method('findRow')
            ->with(42, 'alice')
            ->willReturn(['id' => 42, 'cells' => [['columnId' => 1, 'value' => 'Swing']]]);

        $object = $this->provider()->find($register, $schema, '42', ['tableId' => 5]);

        $this->assertNotNull($object);
        $this->assertSame('Swing', $object->getObject()['name']);
    }//end testFindByRowId()

    /**
     * A denied/absent row is null (denied == absent, no oracle).
     *
     * @return void
     */
    public function testFindDeniedIsNull(): void
    {
        [$register, $schema] = $this->binding();

        $this->reader->method('findRow')->willReturn(null);

        $this->assertNull($this->provider()->find($register, $schema, '42', ['tableId' => 5]));
    }//end testFindDeniedIsNull()

    /**
     * find() by derived UUID resolves via a bounded scan of the bound table.
     *
     * @return void
     */
    public function testFindByDerivedUuid(): void
    {
        [$register, $schema] = $this->binding();
        $uuid = $this->deriver->deriveObjectUuid(tableId: 5, rowId: 42);

        $this->reader->method('listColumns')->willReturn($this->columns());
        $this->reader->method('findRowsByTable')->willReturn(
            [
                ['id' => 41, 'cells' => []],
                ['id' => 42, 'cells' => [['columnId' => 1, 'value' => 'Swing']]],
            ]
        );

        $object = $this->provider()->find($register, $schema, $uuid, ['tableId' => 5]);

        $this->assertNotNull($object);
        $this->assertSame($uuid, $object->getUuid());
        $this->assertSame('Swing', $object->getObject()['name']);
    }//end testFindByDerivedUuid()

    /**
     * count() delegates to the reader for a table and for a View binding.
     *
     * @return void
     */
    public function testCountTableAndView(): void
    {
        [$register, $schema] = $this->binding();

        $this->reader->method('countRows')->willReturnCallback(
            static function (int $id, string $userId, bool $isView) {
                return ($isView === true) ? 7 : 3;
            }
        );

        $this->assertSame(3, $this->provider()->count($register, $schema, [], ['tableId' => 5]));
        $this->assertSame(7, $this->provider()->count($register, $schema, [], ['tableId' => 5, 'viewId' => 9]));
    }//end testCountTableAndView()

    /**
     * A config.viewId binds a Tables View (findRowsByView, not findRowsByTable).
     *
     * @return void
     */
    public function testViewIdBindsView(): void
    {
        [$register, $schema] = $this->binding();

        $this->reader->method('listColumns')->willReturn($this->columns());
        $this->reader->expects($this->once())
            ->method('findRowsByView')
            ->with(9, 'alice', 200, 0)
            ->willReturn([['id' => 1, 'cells' => []]]);
        $this->reader->expects($this->never())->method('findRowsByTable');

        $objects = $this->provider()->findAll($register, $schema, [], ['tableId' => 5, 'viewId' => 9]);

        $this->assertCount(1, $objects);
    }//end testViewIdBindsView()

    /**
     * find() ignores a non-numeric, non-UUID id.
     *
     * @return void
     */
    public function testFindRejectsGarbageId(): void
    {
        [$register, $schema] = $this->binding();
        $this->assertNull($this->provider()->find($register, $schema, 'nonsense', ['tableId' => 5]));
    }//end testFindRejectsGarbageId()
}//end class
