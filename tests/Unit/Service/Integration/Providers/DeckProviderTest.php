<?php

/**
 * Unit tests for DeckProvider.
 *
 * Exercises the full provider contract: metadata, isEnabled/health gate,
 * list() delegation, create() payload routing (link-existing vs.
 * create-new), delete() delegation, and permission contract.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-deck/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\Integration\Providers\DeckProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DeckProviderTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeckProviderTest extends TestCase
{

    /**
     * DeckLinkService mock.
     *
     * @var DeckLinkService&MockObject
     */
    private DeckLinkService&MockObject $service;

    /**
     * IAppManager mock.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * IL10N mock.
     *
     * @var IL10N&MockObject
     */
    private IL10N&MockObject $l10n;

    /**
     * Provider under test.
     *
     * @var DeckProvider
     */
    private DeckProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = $this->createMock(DeckLinkService::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->l10n       = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);

        $this->provider = new DeckProvider(
            deckLinkService: $this->service,
            appManager: $this->appManager,
            l10n: $this->l10n,
        );
    }//end setUp()

    public function testGetIdReturnsDeck(): void
    {
        $this->assertSame('deck', $this->provider->getId());
    }//end testGetIdReturnsDeck()

    public function testGetLabelReturnsCards(): void
    {
        $this->assertSame('Cards', $this->provider->getLabel());
    }//end testGetLabelReturnsCards()

    public function testGetGroupReturnsWorkflow(): void
    {
        $this->assertSame('workflow', $this->provider->getGroup());
    }//end testGetGroupReturnsWorkflow()

    public function testGetRequiredAppReturnsDeck(): void
    {
        $this->assertSame('deck', $this->provider->getRequiredApp());
    }//end testGetRequiredAppReturnsDeck()

    public function testGetStorageStrategyReturnsLinkTable(): void
    {
        $this->assertSame('link-table', $this->provider->getStorageStrategy());
    }//end testGetStorageStrategyReturnsLinkTable()

    public function testRequiresPermissionReturnsNull(): void
    {
        $this->assertNull($this->provider->requiresPermission());
    }//end testRequiresPermissionReturnsNull()

    public function testIsEnabledWhenDeckAvailable(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $this->assertTrue($this->provider->isEnabled());
    }//end testIsEnabledWhenDeckAvailable()

    public function testIsDisabledWhenDeckUnavailable(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(false);
        $this->assertFalse($this->provider->isEnabled());
    }//end testIsDisabledWhenDeckUnavailable()

    public function testHealthReportsOkWhenDeckAvailable(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(true);
        $health = $this->provider->health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReportsOkWhenDeckAvailable()

    public function testHealthReportsUnavailableWhenDeckMissing(): void
    {
        $this->service->method('isDeckAvailable')->willReturn(false);
        $health = $this->provider->health();
        $this->assertSame('unavailable', $health['status']);
        $this->assertNotNull($health['message']);
    }//end testHealthReportsUnavailableWhenDeckMissing()

    public function testListDelegatesToDeckLinkService(): void
    {
        $expected = [['cardId' => 7, 'cardTitle' => 'Fix bug #42']];
        $this->service->method('getLinkedCards')->with('obj-uuid')->willReturn($expected);

        $result = $this->provider->list(register: 'reg', schema: 'sch', objectId: 'obj-uuid');

        $this->assertSame($expected, $result);
    }//end testListDelegatesToDeckLinkService()

    public function testListReturnsEmptyArrayOnServiceException(): void
    {
        $this->service->method('getLinkedCards')->willThrowException(new \RuntimeException('no deck'));

        $result = $this->provider->list(register: 'reg', schema: 'sch', objectId: 'obj-uuid');

        $this->assertSame([], $result);
    }//end testListReturnsEmptyArrayOnServiceException()

    public function testCreateWithCardIdLinksExistingCard(): void
    {
        $link = $this->createMock(DeckLink::class);
        $link->method('jsonSerialize')->willReturn(['cardId' => 5, 'objectUuid' => 'obj-1']);

        $this->service->expects($this->once())
            ->method('linkCard')
            ->with('obj-1', 1, 2, 5)
            ->willReturn($link);

        $result = $this->provider->create(
            register: '1',
            schema: '2',
            objectId: 'obj-1',
            payload: ['cardId' => 5],
        );

        $this->assertSame(5, $result['cardId']);
    }//end testCreateWithCardIdLinksExistingCard()

    public function testCreateWithBoardAndStackCreatesAndLinksCard(): void
    {
        $link = $this->createMock(DeckLink::class);
        $link->method('jsonSerialize')->willReturn(['cardId' => 99, 'objectUuid' => 'obj-2']);

        $this->service->expects($this->once())
            ->method('createAndLinkCard')
            ->with('obj-2', 1, 2, 10, 20, 'New task', null, null)
            ->willReturn($link);

        $result = $this->provider->create(
            register: '1',
            schema: '2',
            objectId: 'obj-2',
            payload: ['boardId' => 10, 'stackId' => 20, 'title' => 'New task'],
        );

        $this->assertSame(99, $result['cardId']);
    }//end testCreateWithBoardAndStackCreatesAndLinksCard()

    public function testCreateWithIncompletePayloadThrowsException(): void
    {
        $this->expectException(Exception::class);

        $this->provider->create(
            register: '1',
            schema: '2',
            objectId: 'obj-3',
            payload: [],
        );
    }//end testCreateWithIncompletePayloadThrowsException()

    public function testDeleteDelegatesToUnlinkCard(): void
    {
        $this->service->expects($this->once())
            ->method('unlinkCard')
            ->with('obj-uuid', 42);

        $this->provider->delete(
            register: 'reg',
            schema: 'sch',
            objectId: 'obj-uuid',
            entityId: '42',
        );
    }//end testDeleteDelegatesToUnlinkCard()
}//end class
