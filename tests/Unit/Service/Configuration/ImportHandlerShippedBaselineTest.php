<?php

declare(strict_types=1);

/**
 * The shipped baseline of an existing schema is recorded only after the
 * schema write succeeds.
 *
 * The import used to record the baseline inside the guard, before
 * `updateFromArray()`. When that write was refused (portaliq's portalPage
 * 0.4.0, a union type the property validator rejects), the instance kept
 * its old properties under a baseline it never ran. The next import then
 * read every old live part as a local edit and kept it, while the version
 * moved on: portalPage 0.4.1 with 0.3.0 properties.
 *
 * The guard here is the real one over the real merge; only its store,
 * audit mapper and session are doubles.
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

use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The baseline follows the write, never precedes it.
 */
class ImportHandlerShippedBaselineTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/**
	 * The baselines the store was asked to record.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * The live 0.3.0 portalPage: one kind.
	 *
	 * @var array<string, mixed>
	 */
	private const LIVE = ['kind' => ['type' => 'string', 'enum' => ['inbox']]];

	/**
	 * The shipped 0.4.x portalPage: three kinds.
	 *
	 * @var array<string, mixed>
	 */
	private const SHIPPED = ['kind' => ['type' => 'string', 'enum' => ['inbox', 'cases', 'timedTask']]];

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('findByApplicationAndSlug')->willReturn($this->stored());
		$this->schemaMapper->method('update')->willReturnArgument(0);
	}//end setUp()

	/**
	 * The stored portalPage schema, still on its old properties.
	 *
	 * @return Schema
	 */
	private function stored(): Schema {
		$schema = new Schema();
		(new ReflectionProperty($schema, 'id'))->setValue($schema, 189);
		$schema->setSlug('portalPage');
		$schema->setTitle('Portal page');
		$schema->setVersion('0.3.0');
		$schema->setProperties(self::LIVE);
		$schema->setRequired([]);

		return $schema;
	}//end stored()

	/**
	 * An import handler with the real guard over a store holding $baseline.
	 *
	 * @param array<string, mixed>|null $baseline The recorded baseline definition, or null for none.
	 *
	 * @return ImportHandler
	 */
	private function handler(?array $baseline): ImportHandler {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(
			static fn (string $slug): string => ('schema:' . $slug)
		);
		$store->method('read')->willReturn(
			($baseline === null ? null : [
				'definition' => $baseline,
				'app' => 'portaliq',
				'appVersion' => '0.56.0',
				'recordedAt' => '2026-10-03T00:00:00+00:00',
			])
		);
		$store->method('record')->willReturnCallback(
			function (string $subject, array $definition): bool {
				$this->recorded[] = $definition;
				return true;
			}
		);

		$parts = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$guard = new ShippedConfigurationGuard(
			baselines: $store,
			merge: new GuardedDescriptorMerge(parts: $parts, comparator: $comparator),
			comparator: $comparator,
			audit: $this->createMock(AuditTrailMapper::class),
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);

		return new ImportHandler(
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class),
			shippedGuard: $guard
		);
	}//end handler()

	/**
	 * The incoming portalPage definition.
	 *
	 * @param string $version The schema version.
	 *
	 * @return array<string, mixed>
	 */
	private function incoming(string $version): array {
		return ['slug' => 'portalPage', 'title' => 'Portal page', 'version' => $version, 'properties' => self::SHIPPED, 'required' => []];
	}//end incoming()

	/**
	 * 🔴 A refused write records no baseline.
	 *
	 * Control: on the old order this test fails, because the guard had
	 * already recorded the incoming definition when updateFromArray threw.
	 *
	 * @return void
	 */
	public function testARefusedWriteRecordsNoBaseline(): void {
		$this->schemaMapper->method('updateFromArray')->willThrowException(new Exception('Invalid type'));

		try {
			$this->handler(baseline: null)->importSchema(data: $this->incoming(version: '0.4.0'), slugsAndIdsMap: [], appId: 'portaliq', version: '0.56.0');
			$this->fail('the refused write must still fail the import');
		} catch (Exception $e) {
			$this->assertStringContainsString('Invalid type', $e->getMessage());
		}

		$this->assertSame([], $this->recorded, 'no baseline the instance never ran');
	}//end testARefusedWriteRecordsNoBaseline()

	/**
	 * A successful write records the baseline, after it, and writes the shipped properties.
	 *
	 * @return void
	 */
	public function testASuccessfulWriteRecordsTheBaselineAfterIt(): void {
		$written = null;
		$this->schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object) use (&$written): Schema {
				$this->assertSame([], $this->recorded, 'the baseline is not recorded before the write');
				$written = $object;
				$schema = $this->stored();
				$schema->setProperties($object['properties']);
				return $schema;
			}
		);

		$this->handler(baseline: null)->importSchema(data: $this->incoming(version: '0.4.1'), slugsAndIdsMap: [], appId: 'portaliq', version: '0.56.1');

		$this->assertSame(self::SHIPPED['kind']['enum'], $written['properties']['kind']['enum'], 'the shipped properties are written');
		$this->assertCount(1, $this->recorded, 'the baseline is recorded once the write succeeded');
		$this->assertSame(self::SHIPPED['kind']['enum'], $this->recorded[0]['properties']['kind']['enum']);
	}//end testASuccessfulWriteRecordsTheBaselineAfterIt()
}//end class
