<?php

/**
 * Golden test pinning which pending values a deployment preview refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationProvenance;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use PHPUnit\Framework\TestCase;

/**
 * Previews one pending value over every combination of removal, base value,
 * live value and proposed value (plus an undeclared key and a set of several
 * drafts), and records the whole preview and every registry, store and draft
 * call in order. So an unknown key, an invalid value and a stale draft are
 * pinned as refused, everything else as its create/change/remove/unchanged
 * verdict, and each refusal as decided before the store is read where it was.
 * Compared with `fixtures/deployment-preview-golden.json`; set
 * `OR_GOLDEN_WRITE=1` to rewrite it.
 */
class DeploymentPreviewAccessGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The fixture path.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/deployment-preview-golden.json';
	}//end goldenPath()

	/**
	 * The values a case can use, by name; `absent` is no value.
	 *
	 * @return array<string, mixed>
	 */
	private static function values(): array {
		return ['absent' => null, 'v1' => 'v1', 'v2' => 'v2', 'map' => ['b' => 1, 'a' => 2], 'map-reordered' => ['a' => 2, 'b' => 1], 'int' => 1, 'string-1' => '1', 'bad' => 'bad'];
	}//end values()

	/**
	 * Case name => drafts, each [key, removes, base, live, proposed].
	 *
	 * @return array<string, array<int, array{0: string, 1: bool, 2: string, 3: string, 4: string}>>
	 */
	private static function corpus(): array {
		$cases = [
			'unknown-key' => [['undeclared', false, 'v1', 'v1', 'v2']],
			'unknown-key-removal' => [['undeclared', true, 'v1', 'v1', 'absent']],
			'several' => [
				['k1', false, 'v1', 'v1', 'v2'],
				['undeclared', false, 'v1', 'v1', 'v2'],
				['k3', false, 'v1', 'v1', 'v1'],
				['k4', true, 'v1', 'v1', 'absent'],
				['k5', false, 'v1', 'v1', 'bad'],
			],
		];
		foreach ([false, true] as $removes) {
			foreach (['absent', 'v1', 'map'] as $base) {
				foreach (['absent', 'v1', 'v2', 'map-reordered', 'string-1'] as $live) {
					foreach (['v1', 'v2', 'map', 'bad', 'int'] as $proposed) {
						$name = ($removes === true ? 'remove' : 'set')."-base-{$base}-live-{$live}-to-{$proposed}";
						$cases[$name] = [['k', $removes, $base, $live, $proposed]];
					}
				}
			}
		}

		return $cases;
	}//end corpus()

	/**
	 * One data set per corpus entry.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * The case's preview and calls equal the recorded golden.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testPreviewMatchesGolden(string $name): void {
		$actual = $this->runCase(drafts: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testPreviewMatchesGolden()

	/**
	 * Preview a set holding the given drafts.
	 *
	 * @param array<int, array{0: string, 1: bool, 2: string, 3: string, 4: string}> $drafts The drafts.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $drafts): array {
		$this->calls = [];
		$values = self::values();
		$live = [];
		$entities = [];
		foreach ($drafts as $index => [$key, $removes, $base, $liveName, $proposed]) {
			$live[$key] = $liveName;
			$draft = new ConfigurationDraft();
			$draft->setUuid('draft-'.$index);
			$draft->setLayer('instance');
			$draft->setConfigKey($key);
			$draft->setRemoves($removes);
			$draft->setBasePresent($base !== 'absent');
			$draft->setBaseValue(['value' => $values[$base]]);
			$draft->setDraftValue(['value' => $values[$proposed]]);
			$entities[] = $draft;
		}

		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setName('tuning');
		$set->setState('open');

		$service = new DeploymentPreviewService($this->drafts(entities: $entities), $this->store(live: $live), $this->registry());
		$out = $service->preview($set);
		return json_decode((string) json_encode(['preview' => $out, 'calls' => $this->calls]), true);
	}//end runCase()

	/**
	 * The draft service double, handing out the drafts.
	 *
	 * @param array<int, ConfigurationDraft> $entities The drafts.
	 *
	 * @return ConfigurationDraftService
	 */
	private function drafts(array $entities): ConfigurationDraftService {
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('draftsIn')->willReturnCallback(
			function (string $setUuid) use ($entities): array {
				$this->calls[] = ['drafts.draftsIn', $setUuid];
				return $entities;
			}
		);
		$drafts->method('requiresFourEyes')->willReturnCallback(
			function (): bool {
				$this->calls[] = ['drafts.requiresFourEyes'];
				return true;
			}
		);
		return $drafts;
	}//end drafts()

	/**
	 * The value store double, answering the live values.
	 *
	 * @param array<string, string> $live Live value name by key.
	 *
	 * @return ConfigurationValueStore
	 */
	private function store(array $live): ConfigurationValueStore {
		$values = self::values();
		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			function (string $layer, ?string $layerRef, string $configKey) use ($live, $values): ConfigurationSnapshot {
				$this->calls[] = ['store.read', $layer, $layerRef, $configKey];
				$name = ($live[$configKey] ?? 'absent');
				if ($name === 'absent') {
					return ConfigurationSnapshot::absent(layer: $layer, layerRef: $layerRef, configKey: $configKey);
				}

				return new ConfigurationSnapshot(new ConfigurationAddress($layer, $layerRef, $configKey), $values[$name], true);
			}
		);
		return $store;
	}//end store()

	/**
	 * The key registry double: `undeclared` is refused, the value `bad` is refused.
	 *
	 * @return ConfigurationKeyRegistry
	 */
	private function registry(): ConfigurationKeyRegistry {
		$registry = $this->createMock(ConfigurationKeyRegistry::class);
		$registry->method('refusalFor')->willReturnCallback(
			function (string $layer, string $configKey): ?string {
				$this->calls[] = ['registry.refusalFor', $layer, $configKey];
				if ($configKey === 'undeclared') {
					return 'not a declared key';
				}

				return null;
			}
		);
		$registry->method('valueRefusalFor')->willReturnCallback(
			function (string $configKey, mixed $value): ?string {
				$this->calls[] = ['registry.valueRefusalFor', $configKey, $value];
				if ($value === 'bad') {
					return 'not a valid value';
				}

				return null;
			}
		);
		return $registry;
	}//end registry()
}//end class
