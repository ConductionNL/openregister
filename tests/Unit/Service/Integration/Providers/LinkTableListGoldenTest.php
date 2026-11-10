<?php

/**
 * Golden record of list() in the providers that read a link table first.
 *
 * For each provider: the app missing, linked rows, a failing link mapper and an
 * empty link table. The marker scan is stubbed and its arguments recorded. The
 * recording lives in fixtures/link-table-list-golden.json; set
 * LINK_LIST_GOLDEN_RECORD=1 to write it.
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
 * @spec exclude Pins the link-table-then-marker list shape the providers share.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Db;
use OCA\OpenRegister\Service\Integration\Providers;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Service\Integration\Providers\LeafMarkerScanning;
use OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform;

/**
 * list() of the link-table providers.
 */
class LinkTableListGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/link-table-list-golden.json';

	/**
	 * Provider class, its link mapper class, its link entity class, and whether it takes a logger.
	 *
	 * @var array<string, array{class-string, class-string, class-string, bool}>
	 */
	private const PROVIDERS = [
		'analytics' => [Providers\AnalyticsProvider::class, Db\AnalyticsLinkMapper::class, Db\AnalyticsLink::class, true],
		'collectives' => [Providers\CollectivesProvider::class, Db\CollectiveLinkMapper::class, Db\CollectiveLink::class, true],
		'maps' => [Providers\MapsProvider::class, Db\MapLinkMapper::class, Db\MapLink::class, true],
		'photos' => [Providers\PhotosProvider::class, Db\PhotoLinkMapper::class, Db\PhotoLink::class, true],
		'cospend' => [Providers\CospendProvider::class, Db\CospendLinkMapper::class, Db\CospendLink::class, false],
	];

	/**
	 * What the marker scan was asked.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $markerCalls = [];

	/**
	 * Build a provider over an app manager, a link mapper and a stubbed marker scan.
	 *
	 * @param array{class-string, class-string, class-string, bool} $spec      The provider spec.
	 * @param bool                                                   $installed Whether the app is installed.
	 * @param mixed                                                  $links     Rows the mapper returns, or an exception.
	 *
	 * @return object The provider.
	 */
	private function provider(array $spec, bool $installed, mixed $links): object {
		[$class, $mapperClass] = $spec;
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);
		$apps->method('getInstalledApps')->willReturn($installed ? ['analytics', 'collectives', 'maps', 'photos', 'cospend'] : []);
		$mapper = $this->createMock($mapperClass);
		if ($links instanceof \Throwable) {
			$mapper->method('findByObjectUuid')->willThrowException($links);
		} else {
			$mapper->method('findByObjectUuid')->willReturn($links);
		}

		$args = [new LeafProviderPlatform($this->createMock(IDBConnection::class), $apps, $this->createMock(IL10N::class)), $mapper, null];
		if ($spec[3] === true) {
			$args = [new LeafMarkerScanning($this->createMock(IDBConnection::class), $apps, $this->createMock(IL10N::class), new NullLogger()), $mapper];
		}
		$builder = $this->getMockBuilder($class)
			->setConstructorArgs($args)
			->onlyMethods(method_exists($class, 'findByMarker') ? ['findByMarker'] : []);
		$provider = $builder->getMock();
		if (method_exists($class, 'findByMarker') === false) {
			return $provider;
		}

		$provider->method('findByMarker')->willReturnCallback(
			function (...$args): array {
				$this->markerCalls[] = array_map(static fn ($a) => is_object($a) ? 'object' : $a, $args);
				return [['id' => 7, 'album_id' => 8, 'name' => 'N', 'slug' => 's', 'title' => 't', 'lat' => 1.5]];
			}
		);
		return $provider;
	}//end provider()

	/**
	 * Two link entities of a class, with ids set.
	 *
	 * @param class-string $linkClass The link entity class.
	 *
	 * @return list<object> The links.
	 */
	private function links(string $linkClass): array {
		$out = [];
		foreach ([1, 2] as $id) {
			$link = new $linkClass();
			$link->setId($id);
			$out[] = $link;
		}

		return $out;
	}//end links()

	/**
	 * Every provider lists as recorded.
	 *
	 * @return void
	 */
	public function testListsMatchTheRecording(): void {
		$seen = [];
		foreach (self::PROVIDERS as $name => $spec) {
			$scenarios = [
				'app missing' => [false, $this->links($spec[2])],
				'linked rows' => [true, $this->links($spec[2])],
				'mapper fails' => [true, new RuntimeException('no table')],
				'no links' => [true, []],
			];
			foreach ($scenarios as $label => [$installed, $links]) {
				$this->markerCalls = [];
				$rows = $this->provider($spec, $installed, $links)->list('r', 's', 'obj-1', ['q' => 1]);
				$seen[$name.', '.$label] = ['rows' => $rows, 'marker' => $this->markerCalls];
			}
		}

		$json = json_decode((string)json_encode($seen, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		if (getenv('LINK_LIST_GOLDEN_RECORD') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestIncomplete('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $json);
	}//end testListsMatchTheRecording()
}//end class
