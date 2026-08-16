<?php

/**
 * Unit tests for BookmarksProvider after Tier-2 migration to
 * BookmarkLinkMapper.
 *
 * Covers:
 *  - metadata getters (id / label / icon / group / requiredApp / storage)
 *  - `isEnabled()` honours `IAppManager::isInstalled()`
 *  - `list()` returns empty when Bookmarks is uninstalled
 *  - `list()` returns empty when no link rows exist
 *  - `list()` happy-path: walks `openregister_bookmark_links` and
 *    normalises each row to
 *    `{id,bookmarkId,title,description,url,tags,added,linkId}`
 *  - `health()` reports `'unavailable'` when Bookmarks is not installed
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
 * @spec openspec/specs/integration-bookmarks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use OCA\OpenRegister\Db\BookmarkLink;
use OCA\OpenRegister\Db\BookmarkLinkMapper;
use OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BookmarksProvider.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class BookmarksProviderTest extends TestCase {

	private function buildL10n(): IL10N {
		$mock = $this->createMock(IL10N::class);
		$mock->method('t')->willReturnArgument(0);
		return $mock;
	}//end buildL10n()

	private function buildAppManager(bool $installed): IAppManager {
		$mock = $this->createMock(IAppManager::class);
		$mock->method('isInstalled')->willReturn($installed);
		return $mock;
	}//end buildAppManager()

	public function testMetadataGetters(): void {
		$mapper = $this->createMock(BookmarkLinkMapper::class);
		$provider = new BookmarksProvider($mapper, $this->buildAppManager(true), $this->buildL10n());

		$this->assertSame('bookmarks', $provider->getId());
		$this->assertSame('Bookmarks', $provider->getLabel());
		$this->assertSame('Bookmark', $provider->getIcon());
		$this->assertSame('docs', $provider->getGroup());
		$this->assertSame('bookmarks', $provider->getRequiredApp());
		$this->assertSame('link-table', $provider->getStorageStrategy());
		$this->assertTrue($provider->isEnabled());
	}//end testMetadataGetters()

	public function testListReturnsEmptyWhenBookmarksUninstalled(): void {
		$mapper = $this->createMock(BookmarkLinkMapper::class);
		$mapper->expects($this->never())->method('findByObjectUuid');

		$provider = new BookmarksProvider($mapper, $this->buildAppManager(false), $this->buildL10n());

		$this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
	}//end testListReturnsEmptyWhenBookmarksUninstalled()

	public function testListReturnsEmptyWhenNoLinks(): void {
		$mapper = $this->createMock(BookmarkLinkMapper::class);
		$mapper->method('findByObjectUuid')->with('obj-uuid')->willReturn([]);

		$provider = new BookmarksProvider($mapper, $this->buildAppManager(true), $this->buildL10n());

		$this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
	}//end testListReturnsEmptyWhenNoLinks()

	public function testListNormalisesLinkRows(): void {
		$link = new BookmarkLink();
		$link->setObjectUuid('obj-uuid');
		$link->setBookmarkId(42);
		$link->setTitle('Conduction');
		$link->setUrl('https://conduction.nl');
		$link->setDescription('Company site');
		$link->setTags(['vendor', 'reference']);
		$link->setAddedAt(new DateTime('2026-06-01T12:00:00+00:00'));

		$mapper = $this->createMock(BookmarkLinkMapper::class);
		$mapper->method('findByObjectUuid')->with('obj-uuid')->willReturn([$link]);

		$provider = new BookmarksProvider($mapper, $this->buildAppManager(true), $this->buildL10n());

		$rows = $provider->list('reg', 'sch', 'obj-uuid');

		$this->assertCount(1, $rows);
		$this->assertSame('42', $rows[0]['id']);
		$this->assertSame(42, $rows[0]['bookmarkId']);
		$this->assertSame('Conduction', $rows[0]['title']);
		$this->assertSame('https://conduction.nl', $rows[0]['url']);
		$this->assertSame('Company site', $rows[0]['description']);
		$this->assertSame(['vendor', 'reference'], $rows[0]['tags']);
		$this->assertNotNull($rows[0]['added']);
	}//end testListNormalisesLinkRows()

	public function testHealthReportsUnavailableWhenUninstalled(): void {
		$mapper = $this->createMock(BookmarkLinkMapper::class);
		$provider = new BookmarksProvider($mapper, $this->buildAppManager(false), $this->buildL10n());

		$health = $provider->health();

		$this->assertSame('unavailable', $health['status']);
	}//end testHealthReportsUnavailableWhenUninstalled()
}//end class
