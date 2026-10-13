<?php

/**
 * Shared set-up for the example-set tests: apps on disk in a temporary
 * folder, an app manager that reports them as enabled, an app config in
 * memory, and the real catalogue, installer and remover on top of an
 * in-memory store and in-memory accounts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetProof;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake\FakeExampleSetAccounts;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake\FakeExampleSetStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Base for the example-set tests.
 */
abstract class ExampleSetTestCase extends TestCase {
	/** @var string Root of the temporary apps. */
	protected string $appsRoot = '';

	/** @var array<string, string> App paths by app id; every app here is enabled. */
	protected array $apps = [];

	/** @var array<string, string> The app config, by key. */
	protected array $config = [];

	protected FakeExampleSetStore $store;

	protected FakeExampleSetAccounts $accounts;

	protected ExampleSetRecord $record;

	protected ExampleSetCatalogue $catalogue;

	protected ExampleSetInstaller $installer;

	protected ExampleSetRemover $remover;

	protected function setUp(): void {
		parent::setUp();
		$this->appsRoot = sys_get_temp_dir().'/or-example-sets-'.bin2hex(random_bytes(6));
		mkdir($this->appsRoot);

		$this->store    = new FakeExampleSetStore();
		$this->accounts = new FakeExampleSetAccounts();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$this->record = new ExampleSetRecord(appConfig: $appConfig);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturnCallback(fn (): array => array_keys($this->apps));
		$appManager->method('getAppPath')->willReturnCallback(fn (string $app): string => $this->apps[$app]);

		$this->catalogue = new ExampleSetCatalogue(appManager: $appManager, order: new ExampleSetOrder());
		$this->installer = new ExampleSetInstaller(
			store: $this->store,
			accounts: $this->accounts,
			values: new ExampleSetValues(),
			record: $this->record,
			proof: new ExampleSetProof()
		);
		$this->remover   = new ExampleSetRemover(store: $this->store, accounts: $this->accounts, record: $this->record);
	}

	protected function tearDown(): void {
		$this->removeTree(path: $this->appsRoot);
		parent::tearDown();
	}

	/**
	 * Enable an app, optionally shipping a fragment of a set.
	 *
	 * @param string                    $app      The app id.
	 * @param string                    $set      The set id.
	 * @param array<string, mixed>|null $fragment The fragment, or null for an app without one.
	 *
	 * @return void
	 */
	protected function app(string $app, string $set = 'demo', ?array $fragment = null): void {
		$path = $this->appsRoot.'/'.$app;
		@mkdir($path.'/'.ExampleSetCatalogue::FOLDER, 0777, true);
		$this->apps[$app] = $path;
		if ($fragment !== null) {
			file_put_contents($path.'/'.ExampleSetCatalogue::FOLDER.'/'.$set.'.json', (string) json_encode($fragment));
		}
	}

	/**
	 * A small cast file: one resident with an account and a BSN, one employee in one group.
	 *
	 * @param array<int, array<string, mixed>> $objects OpenRegister's own objects.
	 *
	 * @return array<string, mixed>
	 */
	protected function castFile(array $objects = []): array {
		return [
			'set'      => 'demo',
			'title'    => 'Demo',
			'timezone' => 'Europe/Amsterdam',
			'cast'     => [
				'groups'        => [['key' => 'staff', 'gid' => 'demo-staff', 'displayName' => 'Staff']],
				'people'        => [
					['key' => 'sanne', 'uid' => 'sanne.devries', 'displayName' => 'Sanne de Vries', 'email' => 'sanne@demo.example',
						'bsn' => '999994001', 'address' => ['number' => 14, 'street' => 'Lindelaan']],
					['key' => 'esra', 'uid' => 'esra.demir', 'displayName' => 'Esra Demir', 'groups' => ['staff']],
				],
				'organisations' => [['key' => 'shop', 'name' => 'Slagerij', 'kvk' => '00001001']],
			],
			'objects'  => $objects,
		];
	}

	/**
	 * A fixed install day.
	 *
	 * @return DateTimeImmutable
	 */
	protected function today(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-13 12:00', new DateTimeZone('Europe/Amsterdam'));
	}

	/**
	 * Remove a folder and everything in it.
	 *
	 * @param string $path The folder.
	 *
	 * @return void
	 */
	private function removeTree(string $path): void {
		if ($path === '' || is_dir($path) === false) {
			return;
		}

		foreach ((array) scandir($path) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$child = $path.'/'.$entry;
			if (is_dir($child) === true) {
				$this->removeTree(path: $child);
				continue;
			}

			unlink($child);
		}

		rmdir($path);
	}
}
