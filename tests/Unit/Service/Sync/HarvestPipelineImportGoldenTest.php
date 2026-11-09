<?php

/**
 * Golden test for HarvestPipelineService::import() record decisions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Sync
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncFailure;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Sync\HarvestImportServices;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each case imports scripted records and records the summary plus every
 * mapper, save, find and log call in order. Covers new / unchanged / changed
 * records, every conflict decision, local-lookup failures, and the saved-uuid
 * fallbacks. Compared with fixtures/harvest-pipeline-import-golden.json; set
 * UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Sync\HarvestPipelineService
 */
class HarvestPipelineImportGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/harvest-pipeline-import-golden.json';

	/**
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$tracked = static fn (string $hash, ?string $uuid = 'obj-1', int $id = 99): array => ['id' => $id, 'hash' => $hash, 'uuid' => $uuid];
		return [
			'new record'                    => [[['ext' => 'a']], []],
			'new record no raw data'        => [[['ext' => 'a', 'raw' => null]], []],
			'previous is the same row'      => [[['ext' => 'a', 'id' => 99, 'previous' => $tracked('other')]], []],
			'unchanged'                     => [[['ext' => 'a', 'previous' => $tracked('same')]], []],
			'unchanged without object uuid' => [[['ext' => 'a', 'previous' => $tracked('same', null)]], []],
			'changed without object uuid'   => [[['ext' => 'a', 'previous' => $tracked('other', null)]], []],
			'changed, local unchanged'      => [[['ext' => 'a', 'previous' => $tracked('local')]], ['strategy' => SyncConflictResolver::LOCAL_WINS]],
			'changed, local changed, source wins' => [[['ext' => 'a', 'previous' => $tracked('other')]], ['strategy' => SyncConflictResolver::SOURCE_WINS]],
			'changed, local changed, local wins'  => [[['ext' => 'a', 'previous' => $tracked('other')]], ['strategy' => SyncConflictResolver::LOCAL_WINS]],
			'changed, local changed, manual'      => [[['ext' => 'a', 'previous' => $tracked('other')]], ['strategy' => SyncConflictResolver::MANUAL]],
			'changed, unknown strategy'           => [[['ext' => 'a', 'previous' => $tracked('other')]], ['strategy' => 'bogus']],
			'changed, no strategy'                => [[['ext' => 'a', 'previous' => $tracked('other')]], ['strategy' => null]],
			'changed, previous hash null'         => [[['ext' => 'a', 'previous' => ['id' => 99, 'hash' => null, 'uuid' => 'obj-1']]], ['strategy' => SyncConflictResolver::LOCAL_WINS]],
			'changed, local missing'              => [[['ext' => 'a', 'previous' => $tracked('other')]], ['strategy' => SyncConflictResolver::LOCAL_WINS, 'local' => 'missing']],
			'changed, local lookup throws'        => [[['ext' => 'a', 'previous' => $tracked('other')]], ['strategy' => SyncConflictResolver::LOCAL_WINS, 'local' => 'throw']],
			'saved uuid empty falls back'         => [[['ext' => 'a', 'previous' => $tracked('other')]], ['saved' => '']],
			'saved uuid throws falls back'        => [[['ext' => 'a', 'previous' => $tracked('other')]], ['saved' => 'throw']],
			'saved uuid not a string'             => [[['ext' => 'a']], ['saved' => 'int']],
			'save fails'                          => [[['ext' => 'a'], ['ext' => 'b']], ['saveThrowsFor' => 'a']],
			'all fail'                            => [[['ext' => 'a']], ['saveThrowsFor' => 'a']],
			'mapping applied'                     => [[['ext' => 'a']], ['mapping' => 5]],
			'mapping unresolvable'                => [[['ext' => 'a']], ['mapping' => 404]],
			'mixed batch'                         => [[['ext' => 'n'], ['ext' => 'u', 'previous' => $tracked('same')], ['ext' => 'c', 'previous' => $tracked('other', 'obj-c')], ['ext' => 'k', 'previous' => $tracked('other', 'obj-k')]], ['strategy' => SyncConflictResolver::MANUAL]],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $records  The records to import.
	 * @param array<string, mixed>             $scenario Collaborator behaviour.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testImport(array $records, array $scenario): void {
		$calls = [];
		$hashOf = static function (array $data): string {
			ksort($data);
			return hash('sha256', (string) json_encode($data));
		};
		$rawFor = static fn (string $ext): array => ['title' => 'T ' . $ext, 'ext' => $ext];
		$localData = ['local' => 'edited'];

		$syncRecords = $this->createMock(SyncRecordMapper::class);
		$syncRecords->method('findByExternalId')->willReturnCallback(
			static function (int $sourceId, string $externalId) use ($records, $hashOf, $rawFor, $localData, &$calls): ?SyncRecord {
				$calls[] = ['findByExternalId', $sourceId, $externalId];
				foreach ($records as $spec) {
					if ($spec['ext'] !== $externalId || isset($spec['previous']) === false) {
						continue;
					}

					$previous = new SyncRecord();
					$previous->setId($spec['previous']['id']);
					$previous->setObjectUuid($spec['previous']['uuid']);
					$hash = match ($spec['previous']['hash']) {
						'same' => $hashOf($rawFor($externalId)),
						'local' => $hashOf($localData),
						null => null,
						default => 'stale-hash',
					};
					$previous->setContentHash($hash);
					return $previous;
				}

				return null;
			}
		);
		$syncRecords->method('transitionStatus')->willReturnCallback(
			static function (SyncRecord $record, string $newStatus) use (&$calls): SyncRecord {
				$calls[] = ['transition', $record->getExternalId(), $newStatus, $record->getContentHash() !== null, $record->getObjectUuid()];
				$record->setStatus($newStatus);
				return $record;
			}
		);
		$syncRecords->method('recordFailure')->willReturnCallback(
			static function (SyncRecord $record, SyncFailure $failure) use (&$calls): SyncRecord {
				$calls[] = ['failure', $record->getExternalId(), $failure->status, $failure->message];
				return $record;
			}
		);

		$mappings = $this->createMock(MappingMapper::class);
		$mappings->method('find')->willReturnCallback(
			static function (int $id) use (&$calls): Mapping {
				$calls[] = ['mapping.find', $id];
				if ($id === 404) {
					throw new \RuntimeException('no mapping');
				}

				return new Mapping();
			}
		);
		$mappingService = $this->createMock(MappingService::class);
		$mappingService->method('executeMapping')->willReturnCallback(
			static fn (Mapping $mapping, array $input): array => ['mapped' => $input['ext']]
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (...$args) use ($scenario, $localData, &$calls) {
				$calls[] = ['object.find', $args[0]];
				$local = ($scenario['local'] ?? 'present');
				if ($local === 'throw') {
					throw new \RuntimeException('lookup failed');
				}

				if ($local === 'missing') {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject($localData);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($scenario, &$calls) {
				$named = $args;
				$calls[] = ['saveObject', $named];
				$object = ($named['object'] ?? $named[0]);
				if (($scenario['saveThrowsFor'] ?? null) === ($object['ext'] ?? null)) {
					throw new \RuntimeException('validation failed');
				}

				$saved = $this->createMock(ObjectEntity::class);
				$mode = ($scenario['saved'] ?? 'uuid');
				if ($mode === 'throw') {
					$saved->method('getUuid')->willThrowException(new \RuntimeException('no uuid'));
				} elseif ($mode === 'int') {
					$saved->method('getUuid')->willReturn(null);
				} else {
					$saved->method('getUuid')->willReturn(($mode === '') ? '' : 'saved-uuid');
				}

				return $saved;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$calls): void {
				$calls[] = ['warning', $message];
			}
		);

		$pipeline = new HarvestPipelineService(
			$syncRecords,
			new HarvestImportServices(
				mappingMapper: $mappings,
				mappingService: $mappingService,
				objectService: $objects,
				conflictResolver: new SyncConflictResolver()
			),
			$logger
		);

		$source = new Source();
		$source->setId(1);
		$source->setUuid('source-uuid');
		$source->setTargetRegister('reg');
		$source->setTargetSchema('schema');
		$source->setConflictStrategy((array_key_exists('strategy', $scenario) === true) ? $scenario['strategy'] : SyncConflictResolver::SOURCE_WINS);
		if (isset($scenario['mapping']) === true) {
			$source->setMappingId($scenario['mapping']);
		}

		$entities = [];
		foreach ($records as $spec) {
			$record = new SyncRecord();
			$record->setId(($spec['id'] ?? 1));
			$record->setExternalId($spec['ext']);
			$record->setRawData((array_key_exists('raw', $spec) === true) ? $spec['raw'] : $rawFor($spec['ext']));
			$entities[] = $record;
		}

		$summary = $pipeline->import($source, $entities, 'exec-1');

		$actual = json_decode((string) json_encode(['summary' => $summary, 'calls' => $calls]), true);
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}
}
