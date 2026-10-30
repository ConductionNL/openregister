<?php

/**
 * A seed object shipped at a higher version reaches an instance that already holds it.
 *
 * 🔴 LIVE DEFECT (Zuiddrecht demo, 14 Oct): dossiq shipped its Woo case type at
 * `@self.version` 1.4.7 with a handling team and a flow. On the upgraded
 * instance the update was refused by dossiq's own save listener (it judged the
 * case type by a flow that was not there yet), the per-object catch swallowed
 * the refusal, and the import still stored its content hash. Every later
 * upgrade fast-skipped on that hash, so the shipped case type never arrived.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Seed objects follow the shipped version through the real importFromJson().
 */
class ImportHandlerSeedObjectUpgradeTest extends TestCase {

	private const CASE_TYPE_UUID = '3c0f5a00-0000-4000-a000-00000000a001';

	private const FLOW_UUID = 'cb967581-3962-5b61-8106-9f75248ffd82';

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private IAppConfig&MockObject $appConfig;

	private ObjectService&MockObject $objectService;

	private ImportHandler $handler;

	/**
	 * Every app-config write, key => value.
	 *
	 * @var array<string, string>
	 */
	private array $configWrites = [];

	/**
	 * Every saveObject call, in order: slug, uuid passed, and whether it was written.
	 *
	 * @var array<int, array{slug: string, uuid: string|null, written: bool}>
	 */
	private array $saves = [];

	protected function setUp(): void {
		parent::setUp();

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->objectService = $this->createMock(ObjectService::class);

		// Nothing stored yet: no version, no hash, so the import runs. What the import writes reads back, so a
		// second import sees the shipped versions the first one remembered.
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->configWrites[$key] ?? '')
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->configWrites[$key] = $value;
				return true;
			}
		);
		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('getSlugToIdMap')->willReturn([]);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);

		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->registerMapper->method('createFromArray')->willReturnCallback(
			function (array $d): Register {
				$register = new Register();
				$register->hydrate(['slug' => $d['slug'], 'version' => '1.0.0']);
				$register->setId(23);
				return $register;
			}
		);
		$this->registerMapper->method('update')->willReturnArgument(0);
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			function (array $d): Schema {
				$schema = new Schema();
				$schema->hydrate(['slug' => $d['slug'], 'title' => $d['slug'], 'version' => '1.0.0', 'properties' => []]);
				$schema->setId(($d['slug'] === 'caseType') ? 165 : 194);
				return $schema;
			}
		);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->schemaMapper,
			registerMapper:      $this->registerMapper,
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $mappingMapper,
			client:              $this->createMock(Client::class),
			appConfig:           $this->appConfig,
			logger:              $this->createMock(LoggerInterface::class),
			appDataPath:         '/tmp',
			uploadHandler:       $this->createMock(UploadHandler::class),
			objectService:       $this->objectService
		);
	}//end setUp()

	/**
	 * The instance holds the Woo case type: at version 1 with no handling and no flow, or as shipped.
	 *
	 * @param string $storedVersion The stored `@self.version`.
	 * @param bool   $asShipped     Whether the stored content is what the release ships.
	 *
	 * @return void
	 */
	private function instanceHoldsCaseType(string $storedVersion, bool $asShipped = false): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			static function (array $query) use ($storedVersion, $asShipped): array {
				if (($query['@self']['slug'] ?? '') !== 'woo-verzoek') {
					return [];
				}

				return [[
					'@self' => ['id' => self::CASE_TYPE_UUID, 'version' => $storedVersion, 'slug' => 'woo-verzoek'],
					'id' => self::CASE_TYPE_UUID,
					'title' => 'Woo-verzoek',
					'handling' => ($asShipped === true) ? ['defaultGroup' => 'behandelaars'] : null,
					'workflowDefinition' => ($asShipped === true) ? self::FLOW_UUID : null,
				]];
			}
		);
	}//end instanceHoldsCaseType()

	/**
	 * Saves succeed, except where `$refuse` says how a case type save fails.
	 *
	 * @param callable(int): ?\Throwable $refuse Given the case type attempt number, the failure or null.
	 *
	 * @return void
	 */
	private function saves(callable $refuse): void {
		$attempt = 0;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($refuse, &$attempt): ObjectEntity {
				$slug = (string)($object['@self']['slug'] ?? '');
				if ($slug === 'woo-verzoek') {
					$attempt++;
					$failure = $refuse($attempt);
					if ($failure !== null) {
						$this->saves[] = ['slug' => $slug, 'uuid' => $uuid, 'written' => false];
						throw $failure;
					}
				}

				$this->saves[] = ['slug' => $slug, 'uuid' => $uuid, 'written' => true];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'random');
				return $entity;
			}
		);
	}//end saves()

	/**
	 * Import dossiq's configuration: the case type at 1.4.7, then the flow it names.
	 *
	 * @param string $shippedVersion The case type's shipped `@self.version`.
	 *
	 * @return array<string, mixed> The import result.
	 */
	private function importDossiq(string $shippedVersion='1.4.7'): array {
		$data = [
			'appId' => 'dossiq',
			'version' => '0.4.48',
			'components' => [
				'schemas' => [
					'caseType' => ['slug' => 'caseType', 'title' => 'Case type', 'properties' => []],
					'workflowTemplate' => ['slug' => 'workflowTemplate', 'title' => 'Flow', 'properties' => []],
				],
				'registers' => ['dossiq' => ['slug' => 'dossiq', 'version' => '1.0.0', 'schemas' => ['caseType', 'workflowTemplate']]],
				'objects' => [
					[
						'@self' => ['register' => 'dossiq', 'schema' => 'caseType', 'slug' => 'woo-verzoek', 'version' => $shippedVersion],
						'id' => self::CASE_TYPE_UUID,
						'uuid' => self::CASE_TYPE_UUID,
						'title' => 'Woo-verzoek',
						'handling' => ['defaultGroup' => 'behandelaars'],
						'workflowDefinition' => self::FLOW_UUID,
					],
					[
						'@self' => ['register' => 'dossiq', 'schema' => 'workflowTemplate', 'slug' => 'woo-verzoek-flow', 'version' => '1.0.0'],
						'id' => self::FLOW_UUID,
						'uuid' => self::FLOW_UUID,
						'title' => 'Woo-verzoek',
						'caseType' => self::CASE_TYPE_UUID,
					],
				],
			],
		];

		return $this->handler->importFromJson(data: $data, configuration: new Configuration(), appId: 'dossiq', version: '0.4.48');
	}//end importDossiq()

	/**
	 * 🔴 The live case: v1 on the instance, v1.4.7 shipped, refused until its flow exists.
	 *
	 * The case type is listed before the flow it names, and the listener refuses
	 * it while that flow is missing. The retry after the rest of the list lands
	 * it, as an UPDATE of the stored row, carrying the shipped version.
	 *
	 * @return void
	 */
	public function testAStoredSeedUpdatesToTheShippedVersionAfterItsFlowIsWritten(): void {
		$this->instanceHoldsCaseType(storedVersion: '1');
		$this->saves(
			static fn (int $attempt): ?\Throwable => ($attempt === 1) ? new HookStoppedException('A case in Ontvangst cannot move to Afgehandeld') : null
		);

		$result = $this->importDossiq();

		$this->assertSame(
			[
				['slug' => 'woo-verzoek', 'uuid' => self::CASE_TYPE_UUID, 'written' => false],
				['slug' => 'woo-verzoek-flow', 'uuid' => self::FLOW_UUID, 'written' => true],
				['slug' => 'woo-verzoek', 'uuid' => self::CASE_TYPE_UUID, 'written' => true],
			],
			$this->saves,
			'the flow is written, then the case type is retried as an update of the stored row'
		);
		$this->assertSame([], $result['failed']['objects']);
		$this->assertSame(0, $result['skipped']['objects']);
		$this->assertNotSame('', ($this->configWrites['imported_config_dossiq_hash'] ?? ''), 'a complete import is remembered');
	}//end testAStoredSeedUpdatesToTheShippedVersionAfterItsFlowIsWritten()

	/**
	 * A refusal that holds on the retry is named, and the import is not remembered as done.
	 *
	 * The next run (a later repair step, the next upgrade) imports again instead
	 * of fast-skipping on an unchanged hash.
	 *
	 * @return void
	 */
	public function testAListenerRefusalWithholdsTheHashSoTheNextRunRetries(): void {
		$this->instanceHoldsCaseType(storedVersion: '1');
		$this->saves(static fn (int $attempt): ?\Throwable => new HookStoppedException('refused'));

		$result = $this->importDossiq();

		$this->assertCount(1, $result['failed']['objects']);
		$this->assertSame('woo-verzoek', $result['failed']['objects'][0]['slug']);
		$this->assertTrue($result['failed']['objects'][0]['refusedByListener']);
		$this->assertSame(1, $result['skipped']['objects']);
		$this->assertArrayHasKey('imported_config_dossiq_hash', $this->configWrites);
		$this->assertSame('', $this->configWrites['imported_config_dossiq_hash']);
	}//end testAListenerRefusalWithholdsTheHashSoTheNextRunRetries()

	/**
	 * A failure the file itself causes does not make every repair re-import.
	 *
	 * @return void
	 */
	public function testAValidationFailureKeepsTheHash(): void {
		$this->instanceHoldsCaseType(storedVersion: '1');
		$this->saves(static fn (int $attempt): ?\Throwable => new ValidationException("Property 'caseType' should match format 'uuid'"));

		$result = $this->importDossiq();

		$this->assertCount(1, $result['failed']['objects']);
		$this->assertFalse($result['failed']['objects'][0]['refusedByListener']);
		$this->assertNotSame('', ($this->configWrites['imported_config_dossiq_hash'] ?? ''));
	}//end testAValidationFailureKeepsTheHash()

	/**
	 * 🔴 A seed the instance edited once still receives the next shipped version.
	 *
	 * Imported at 1.4.7, the stored object's own version moves with every later
	 * save: one edit reads 1.4.8. The file then ships 1.4.8, which compares equal
	 * against the object's own version and would never arrive. The import
	 * compares against the version it last SHIPPED (1.4.7), so it does.
	 *
	 * @return void
	 */
	public function testASeedEditedOnTheInstanceStillReceivesTheNextShippedVersion(): void {
		$this->instanceHoldsCaseType(storedVersion: '1.4.8');
		$this->configWrites['imported_seed_versions_dossiq'] = (string)json_encode(['23/165/woo-verzoek' => '1.4.7']);
		$this->saves(static fn (int $attempt): ?\Throwable => null);

		$result = $this->importDossiq(shippedVersion: '1.4.8');

		$this->assertSame(0, $result['unchanged']['objects']);
		$this->assertSame(
			[['slug' => 'woo-verzoek', 'uuid' => self::CASE_TYPE_UUID, 'written' => true], ['slug' => 'woo-verzoek-flow', 'uuid' => self::FLOW_UUID, 'written' => true]],
			$this->saves,
			'the shipped values arrive although the stored object reads the same version'
		);
		$this->assertSame('1.4.8', json_decode($this->configWrites['imported_seed_versions_dossiq'], true)['23/165/woo-verzoek'], 'and the new shipped version is remembered');
	}//end testASeedEditedOnTheInstanceStillReceivesTheNextShippedVersion()

	/**
	 * A seed stored before the import remembered anything is read as it was: the stored version is the baseline,
	 * and the version the file ships is remembered from then on.
	 *
	 * @return void
	 */
	public function testASeedStoredBeforeTheLedgerExistedIsRememberedAtTheShippedVersion(): void {
		$this->instanceHoldsCaseType(storedVersion: '1.4.7', asShipped: true);
		$this->saves(static fn (int $attempt): ?\Throwable => null);

		$result = $this->importDossiq(shippedVersion: '1.4.7');

		$this->assertSame(1, $result['unchanged']['objects']);
		$this->assertSame('1.4.7', json_decode($this->configWrites['imported_seed_versions_dossiq'], true)['23/165/woo-verzoek']);
	}//end testASeedStoredBeforeTheLedgerExistedIsRememberedAtTheShippedVersion()

	/**
	 * A seed at the shipped version is left alone even when edited: the shipped version has not moved.
	 *
	 * @return void
	 */
	public function testAnEditedSeedIsLeftAloneWhileTheShippedVersionHasNotMoved(): void {
		$this->instanceHoldsCaseType(storedVersion: '1.4.9', asShipped: true);
		$this->configWrites['imported_seed_versions_dossiq'] = (string)json_encode(['23/165/woo-verzoek' => '1.4.7']);
		$this->saves(static fn (int $attempt): ?\Throwable => null);

		$result = $this->importDossiq(shippedVersion: '1.4.7');

		$this->assertSame(1, $result['unchanged']['objects']);
		$this->assertSame([['slug' => 'woo-verzoek-flow', 'uuid' => self::FLOW_UUID, 'written' => true]], $this->saves);
	}//end testAnEditedSeedIsLeftAloneWhileTheShippedVersionHasNotMoved()

	/**
	 * A stored seed at the shipped version is left alone: the instance's own data is not rewritten.
	 *
	 * @return void
	 */
	public function testASeedAtTheSameVersionIsLeftAlone(): void {
		$this->instanceHoldsCaseType(storedVersion: '1.4.7', asShipped: true);
		$this->saves(static fn (int $attempt): ?\Throwable => null);

		$result = $this->importDossiq(shippedVersion: '1.4.7');

		$this->assertSame([['slug' => 'woo-verzoek-flow', 'uuid' => self::FLOW_UUID, 'written' => true]], $this->saves);
		$this->assertSame(1, $result['unchanged']['objects']);
	}//end testASeedAtTheSameVersionIsLeftAlone()

	/**
	 * A seed stored before the import kept a content hash, at an unchanged version, gains the shipped fields it lacks.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testASeedThatLacksAShippedFieldGainsIt(): void {
		$this->instanceHoldsCaseType(storedVersion: '1.4.7');
		$this->saves(static fn (int $attempt): ?\Throwable => null);

		$result = $this->importDossiq(shippedVersion: '1.4.7');

		$this->assertContains(['slug' => 'woo-verzoek', 'uuid' => self::CASE_TYPE_UUID, 'written' => true], $this->saves);
		$this->assertSame(0, $result['unchanged']['objects']);
		$this->assertArrayHasKey('23/165/woo-verzoek', json_decode($this->configWrites['imported_seed_hashes_dossiq'], true));
	}//end testASeedThatLacksAShippedFieldGainsIt()

	/**
	 * Shipped content that differs from what was last shipped arrives without a version bump.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testChangedShippedContentArrivesAtAnUnchangedVersion(): void {
		$this->instanceHoldsCaseType(storedVersion: '1.4.7', asShipped: true);
		$this->configWrites['imported_seed_hashes_dossiq'] = (string)json_encode(['23/165/woo-verzoek' => 'the content shipped before']);
		$this->saves(static fn (int $attempt): ?\Throwable => null);

		$result = $this->importDossiq(shippedVersion: '1.4.7');

		$this->assertContains(['slug' => 'woo-verzoek', 'uuid' => self::CASE_TYPE_UUID, 'written' => true], $this->saves);
		$this->assertSame(0, $result['unchanged']['objects']);
	}//end testChangedShippedContentArrivesAtAnUnchangedVersion()

	/**
	 * Shipped content equal to what was last shipped leaves the stored seed, edits included, alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testUnchangedShippedContentIsNotWrittenAgain(): void {
		$this->instanceHoldsCaseType(storedVersion: '1.4.7', asShipped: true);
		$this->saves(static fn (int $attempt): ?\Throwable => null);
		$this->importDossiq(shippedVersion: '1.4.7');
		unset($this->configWrites['imported_config_dossiq_hash']);
		$this->saves = [];

		$result = $this->importDossiq(shippedVersion: '1.4.7');

		$this->assertNotContains(['slug' => 'woo-verzoek', 'uuid' => self::CASE_TYPE_UUID, 'written' => true], $this->saves);
		$this->assertSame(1, $result['unchanged']['objects']);
	}//end testUnchangedShippedContentIsNotWrittenAgain()

	/**
	 * A seed whose write fails is not remembered at the shipped content, so the next import tries it again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testAFailedWriteIsNotRememberedAsShipped(): void {
		$this->instanceHoldsCaseType(storedVersion: '1.4.7');
		$this->saves(static fn (int $attempt): ?\Throwable => new \OCA\OpenRegister\Exception\ValidationException('refused'));

		$this->importDossiq(shippedVersion: '1.4.7');

		$hashes = json_decode(($this->configWrites['imported_seed_hashes_dossiq'] ?? '{}'), true);
		$this->assertArrayNotHasKey('23/165/woo-verzoek', $hashes);
	}//end testAFailedWriteIsNotRememberedAsShipped()
}//end class
