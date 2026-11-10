<?php

declare(strict_types=1);

/**
 * The import result names the schema parts the shipped-baseline guard kept local.
 *
 * When an app and the instance both changed a part, the guard keeps the
 * instance's part and does not abort (REQ-LCA-003). The import result lists
 * those parts so the app and the administrator can see them.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Kept-local parts are reported, never dropped from view.
 */
class ImportHandlerKeptLocalReportTest extends TestCase {

	/**
	 * What the app shipped last time.
	 *
	 * @var array<string, mixed>
	 */
	private const BASELINE = ['read' => ['medewerkers']];

	/**
	 * What the instance runs: the app's rule plus a local team.
	 *
	 * @var array<string, mixed>
	 */
	private const LIVE = ['read' => ['medewerkers', 'behandelaars']];

	/**
	 * What the app ships now: its rule plus `readGrants`.
	 *
	 * @var array<string, mixed>
	 */
	private const SHIPPED = ['read' => ['medewerkers'], 'readGrants' => ['medewerkers']];

	/**
	 * The definition each write received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The stored case schema.
	 *
	 * @return Schema
	 */
	private function stored(): Schema {
		$schema = new Schema();
		(new ReflectionProperty($schema, 'id'))->setValue($schema, 172);
		$schema->setSlug('case');
		$schema->setTitle('Case');
		$schema->setVersion('1.61.0');
		$schema->setProperties(['assignee' => ['type' => 'string']]);
		$schema->setRequired([]);
		$schema->setAuthorization(self::LIVE);

		return $schema;
	}//end stored()

	/**
	 * The incoming case definition.
	 *
	 * @param array<string, mixed> $authorization The shipped block.
	 *
	 * @return array<string, mixed>
	 */
	private static function incoming(array $authorization): array {
		return [
			'slug' => 'case',
			'title' => 'Case',
			'version' => '1.61.0',
			'properties' => ['assignee' => ['type' => 'string']],
			'required' => [],
			'authorization' => $authorization,
		];
	}//end incoming()

	/**
	 * An import handler whose JSON import writes the given case definitions through the real guard.
	 *
	 * @param array<int, array<string, mixed>> $definitions The case definitions each import writes.
	 *
	 * @return ImportHandler
	 */
	private function handler(array $definitions): ImportHandler {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findByApplicationAndSlug')->willReturnCallback(fn (): Schema => $this->stored());
		$schemaMapper->method('update')->willReturnArgument(0);
		$schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object): Schema {
				$this->written[] = $object;
				return $this->stored();
			}
		);

		$configMapper = $this->createMock(ConfigurationMapper::class);
		$configMapper->method('findByApp')->willReturn([]);
		$configMapper->method('insert')->willReturnCallback(
			static function (Configuration $configuration): Configuration {
				$configuration->setId(41);
				return $configuration;
			}
		);
		$configMapper->method('update')->willReturnArgument(0);

		$handler = new class (
			schemaMapper: $schemaMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $configMapper,
			mappingMapper: $this->createMock(MappingMapper::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class),
			shippedGuard: $this->guard()
		) extends ImportHandler {

			/**
			 * The case definitions, one per import.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $definitions = [];

			/**
			 * Import the next case definition through importSchema().
			 *
			 * @param mixed ...$args The import arguments.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromJson(mixed ...$args): array {
				$this->importSchema(data: array_shift($this->definitions), slugsAndIdsMap: [], appId: 'dossiq', version: '0.20.90');

				return ['registers' => [], 'schemas' => [], 'objects' => []];
			}//end importFromJson()
		};
		$handler->definitions = $definitions;

		return $handler;
	}//end handler()

	/**
	 * The real guard over a store holding BASELINE for the case schema.
	 *
	 * @return ShippedConfigurationGuard
	 */
	private function guard(): ShippedConfigurationGuard {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(static fn (string $slug): string => ('schema:' . $slug));
		$store->method('read')->willReturn(
			[
				'definition' => [
					'properties' => ['assignee' => ['type' => 'string']],
					'required' => [],
					'authorization' => self::BASELINE,
				],
				'app' => 'dossiq',
				'appVersion' => '0.20.89',
				'recordedAt' => '2026-11-02T19:58:29+00:00',
			]
		);
		$store->method('record')->willReturn(true);

		$parts = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new ShippedConfigurationGuard(
			baselines: $store,
			merge: new GuardedDescriptorMerge(parts: $parts, comparator: $comparator),
			comparator: $comparator,
			audit: $this->createMock(AuditTrailMapper::class),
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end guard()

	/**
	 * A block both sides changed is kept local, written, and named in the result.
	 *
	 * @return void
	 */
	public function testTheResultNamesTheAuthorizationTheGuardKeptLocal(): void {
		$result = $this->handler(definitions: [self::incoming(authorization: self::SHIPPED)])
			->importFromApp(appId: 'dossiq', data: [], version: '0.20.90');

		$this->assertEqualsCanonicalizing(self::LIVE, $this->written[0]['authorization'], 'the instance block is still the one written');
		$this->assertSame(
			['schemas' => [['slug' => 'case', 'paths' => ['authorization']]]],
			$result['keptLocal']
		);
	}//end testTheResultNamesTheAuthorizationTheGuardKeptLocal()

	/**
	 * An import with no conflict reports an empty list, and an earlier import's list does not carry over.
	 *
	 * @return void
	 */
	public function testAnImportWithoutConflictsReportsNone(): void {
		$handler = $this->handler(
			definitions: [self::incoming(authorization: self::SHIPPED), self::incoming(authorization: self::BASELINE)]
		);

		$first = $handler->importFromApp(appId: 'dossiq', data: [], version: '0.20.90');
		$second = $handler->importFromApp(appId: 'dossiq', data: [], version: '0.20.91');

		$this->assertCount(1, $first['keptLocal']['schemas']);
		$this->assertSame(['schemas' => []], $second['keptLocal']);
	}//end testAnImportWithoutConflictsReportsNone()
	/**
	 * A conflict kept by an import outside an app import is not reported by the next app import.
	 *
	 * @return void
	 */
	public function testAnEarlierImportIsNotReportedByTheNextAppImport(): void {
		$handler = $this->handler(definitions: [self::incoming(authorization: self::BASELINE)]);
		$handler->importSchema(data: self::incoming(authorization: self::SHIPPED), slugsAndIdsMap: [], appId: 'dossiq', version: '0.20.90');

		$result = $handler->importFromApp(appId: 'dossiq', data: [], version: '0.20.91');

		$this->assertSame(['schemas' => []], $result['keptLocal']);
	}//end testAnEarlierImportIsNotReportedByTheNextAppImport()
}//end class
