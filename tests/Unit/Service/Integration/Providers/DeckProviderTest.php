<?php

/**
 * Unit tests for DeckProvider.
 *
 * Covers:
 *  - metadata getters (id / label / icon / group / requiredApp / storage)
 *  - `isEnabled()` honours `DeckLinkService::isDeckAvailable()`
 *  - `list()` returns linked cards from the link service
 *  - `list()` swallows Throwables and returns []
 *  - `create()` routes `{cardId}` payload to `linkCard`
 *  - `create()` routes `{boardId,stackId,title}` payload to `createAndLinkCard`
 *  - `create()` throws when neither variant present
 *  - `delete()` delegates to `unlinkCard`
 *  - `health()` reports `'unavailable'` when Deck app is missing
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
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
 * @spec openspec/changes/integration-deck/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\Integration\Providers\DeckProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for DeckProvider.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Many small AAA tests, one per behaviour.
 */
class DeckProviderTest extends TestCase
{


    private function buildL10n(): IL10N
    {
        $mock = $this->createMock(IL10N::class);
        $mock->method('t')->willReturnArgument(0);
        return $mock;
    }//end buildL10n()


    private function buildAppManager(): IAppManager
    {
        return $this->createMock(IAppManager::class);
    }//end buildAppManager()


    private function buildDeckLink(int $cardId=42): DeckLink
    {
        $link = new DeckLink();
        $link->setObjectUuid('obj-uuid');
        $link->setCardId($cardId);
        $link->setBoardId(1);
        $link->setStackId(2);
        $link->setRegisterId(3);
        $link->setSchemaId(4);
        return $link;
    }//end buildDeckLink()


    public function testMetadataGetters(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $linkService->method('isDeckAvailable')->willReturn(true);

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $this->assertSame('deck', $provider->getId());
        $this->assertSame('Cards', $provider->getLabel());
        $this->assertSame('ViewColumnOutline', $provider->getIcon());
        $this->assertSame('workflow', $provider->getGroup());
        $this->assertSame('deck', $provider->getRequiredApp());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertTrue($provider->isEnabled());
    }//end testMetadataGetters()


    public function testIsEnabledFalseWhenDeckUnavailable(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $linkService->method('isDeckAvailable')->willReturn(false);

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $this->assertFalse($provider->isEnabled());
    }//end testIsEnabledFalseWhenDeckUnavailable()


    public function testListReturnsLinkedCards(): void
    {
        $expected = [['id' => 1, 'title' => 'Card A']];

        $linkService = $this->createMock(DeckLinkService::class);
        $linkService->method('getLinkedCards')->with('obj-uuid')->willReturn($expected);

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $this->assertSame($expected, $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListReturnsLinkedCards()


    public function testListSwallowsThrowableAndReturnsEmpty(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $linkService->method('getLinkedCards')->willThrowException(new RuntimeException('boom'));

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListSwallowsThrowableAndReturnsEmpty()


    public function testCreateLinksExistingCardWhenCardIdPresent(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $linkService
            ->expects($this->once())
            ->method('linkCard')
            ->with('obj-uuid', 10, 20, 42)
            ->willReturn($this->buildDeckLink(42));

        $linkService->expects($this->never())->method('createAndLinkCard');

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $result = $provider->create(
            'reg',
            'sch',
            'obj-uuid',
            [
                'cardId'     => 42,
                'registerId' => 10,
                'schemaId'   => 20,
            ]
        );

        $this->assertIsArray($result);
        $this->assertSame(42, $result['cardId']);
    }//end testCreateLinksExistingCardWhenCardIdPresent()


    public function testCreateCreatesAndLinksWhenBoardStackPresent(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $linkService->expects($this->never())->method('linkCard');
        $linkService
            ->expects($this->once())
            ->method('createAndLinkCard')
            ->with('obj-uuid', 10, 20, 1, 2, 'Hello', 'desc', '2026-12-31')
            ->willReturn($this->buildDeckLink(99));

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $result = $provider->create(
            'reg',
            'sch',
            'obj-uuid',
            [
                'boardId'     => 1,
                'stackId'     => 2,
                'title'       => 'Hello',
                'description' => 'desc',
                'duedate'     => '2026-12-31',
                'registerId'  => 10,
                'schemaId'    => 20,
            ]
        );

        $this->assertIsArray($result);
        $this->assertSame(99, $result['cardId']);
    }//end testCreateCreatesAndLinksWhenBoardStackPresent()


    public function testCreateThrowsWhenPayloadMissing(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $provider    = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $this->expectException(Exception::class);

        $provider->create('reg', 'sch', 'obj-uuid', []);
    }//end testCreateThrowsWhenPayloadMissing()


    public function testDeleteDelegatesToUnlinkCard(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $linkService->expects($this->once())->method('unlinkCard')->with('obj-uuid', 42);

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $provider->delete('reg', 'sch', 'obj-uuid', '42');
    }//end testDeleteDelegatesToUnlinkCard()


    public function testHealthReportsOkWhenAvailable(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $linkService->method('isDeckAvailable')->willReturn(true);

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $health = $provider->health();

        $this->assertSame('ok', $health['status']);
        $this->assertNull($health['message']);
    }//end testHealthReportsOkWhenAvailable()


    public function testHealthReportsUnavailableWhenDeckMissing(): void
    {
        $linkService = $this->createMock(DeckLinkService::class);
        $linkService->method('isDeckAvailable')->willReturn(false);

        $provider = new DeckProvider($linkService, $this->buildAppManager(), $this->buildL10n());

        $health = $provider->health();

        $this->assertSame('unavailable', $health['status']);
        $this->assertSame('NC Deck app is not installed', $health['message']);
    }//end testHealthReportsUnavailableWhenDeckMissing()
}//end class
