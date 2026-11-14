<?php

/**
 * Unit tests for CommentsCollectionCatalog: the collection names, which
 * objects each collection accepts, and each collection's display name and
 * icon.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @spec openspec/changes/platform-comments-entity/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Service\CommentsCollectionCatalog;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\CommentsCollectionCatalog
 * @uses   \OCA\OpenRegister\Dto\DeepLinkRegistration
 */
class CommentsCollectionCatalogTest extends TestCase {

	private DeepLinkRegistryServiceInterface&MockObject $deepLinks;

	private IAppManager&MockObject $apps;

	private IURLGenerator&MockObject $urls;

	protected function setUp(): void {
		parent::setUp();
		$this->deepLinks = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->urls = $this->createMock(IURLGenerator::class);
	}

	private function catalog(): CommentsCollectionCatalog {
		return new CommentsCollectionCatalog($this->deepLinks, $this->apps, $this->urls, $this->createMock(LoggerInterface::class));
	}

	private function claims(string ...$appIds): void {
		$registrations = [];
		foreach ($appIds as $index => $appId) {
			$registrations[] = new DeepLinkRegistration($appId, 'register-' . $index, 'schema-' . $index, '/apps/' . $appId . '/#/{uuid}');
		}

		$this->deepLinks->method('registrations')->willReturn($registrations);
	}

	public function testTheLegacyCollectionComesFirstAndEachClaimingAppOnce(): void {
		$this->claims('dossiq', 'pipelinq', 'dossiq', ' ', 'openregister');

		$this->assertSame(['openregister', 'dossiq', 'pipelinq'], $this->catalog()->names());
	}

	public function testWithoutClaimsOnlyTheLegacyCollectionExists(): void {
		$this->claims();

		$this->assertSame(['openregister'], $this->catalog()->names());
	}

	public function testAnAppsCollectionAcceptsOnlyThePairsThatAppClaimed(): void {
		$this->deepLinks->method('resolveAppId')->willReturnMap(
			[
				[3, 7, 'dossiq'],
				[3, 8, 'pipelinq'],
				[4, 9, null],
			]
		);
		$catalog = $this->catalog();

		$this->assertTrue($catalog->accepts('dossiq', 3, 7));
		$this->assertFalse($catalog->accepts('dossiq', 3, 8));
		$this->assertFalse($catalog->accepts('dossiq', 4, 9));
	}

	public function testTheLegacyCollectionAcceptsEveryObject(): void {
		$this->deepLinks->expects($this->never())->method('resolveAppId');

		$this->assertTrue($this->catalog()->accepts('openregister', 4, 9));
	}

	public function testEachCollectionCarriesTheAppsNameAndIcon(): void {
		$this->claims('dossiq', 'unnamed', 'broken');
		$this->apps->method('getAppInfo')->willReturnCallback(
			static function (string $appId): ?array {
				if ($appId === 'broken') {
					throw new RuntimeException('appinfo unreadable');
				}

				return ['dossiq' => ['name' => 'Dossiq'], 'unnamed' => ['name' => '  ']][$appId] ?? null;
			}
		);
		$this->urls->method('imagePath')->willReturnCallback(
			static function (string $appId, string $image): string {
				if ($appId === 'unnamed') {
					throw new RuntimeException('image not found');
				}

				return '/apps/' . $appId . '/img/' . $image;
			}
		);

		$this->assertSame(
			[
				'openregister' => ['displayName' => 'OpenRegister', 'icon' => '/apps/openregister/img/app.svg'],
				'dossiq' => ['displayName' => 'Dossiq', 'icon' => '/apps/dossiq/img/app.svg'],
				'unnamed' => ['displayName' => 'unnamed', 'icon' => ''],
				'broken' => ['displayName' => 'broken', 'icon' => '/apps/broken/img/app.svg'],
			],
			$this->catalog()->collections()
		);
	}
}
