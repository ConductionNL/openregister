<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * What importSchema() asks the shipped-baseline guard, writes and records.
 */
class ImportHandlerShippedGuardGoldenTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Each guard question, as positional arguments.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $asked = [];

	/**
	 * Each recorded baseline: its arguments and the writes done by then.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $recorded = [];

	/**
	 * The definitions written, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('update')->willReturnArgument(0);
		$this->schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object): Schema {
				$this->written[] = $object;
				return $this->stored();
			}
		);
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			function (array $object): Schema {
				$this->written[] = $object;
				return $this->stored();
			}
		);
	}//end setUp()

	/**
	 * The stored schema, with no authorization set.
	 *
	 * @return Schema
	 */
	private function stored(): Schema {
		$schema = new Schema();
		(new ReflectionProperty($schema, 'id'))->setValue($schema, 7);
		$schema->setSlug('page');
		$schema->setTitle('Page');
		$schema->setVersion('1.0.0');
		$schema->setProperties(['live' => ['type' => 'string']]);
		$schema->setRequired(['live']);

		return $schema;
	}//end stored()

	/**
	 * Make the import find the stored schema.
	 *
	 * @return void
	 */
	private function schemaExists(): void {
		$this->schemaMapper->method('findByApplicationAndSlug')->willReturn($this->stored());
		$this->schemaMapper->method('find')->willReturn($this->stored());
	}//end schemaExists()

	/**
	 * Make the import find no schema.
	 *
	 * @return void
	 */
	private function schemaIsNew(): void {
		$this->schemaMapper->method('findByApplicationAndSlug')->willReturn(null);
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('none'));
	}//end schemaIsNew()

	/**
	 * A guard that answers every question with $answer.
	 *
	 * @param array<string, mixed> $answer The guard's result.
	 *
	 * @return ShippedConfigurationGuard
	 */
	private function guard(array $answer): ShippedConfigurationGuard {
		$guard = $this->createMock(ShippedConfigurationGuard::class);
		$guard->method('guardSchemaUpdate')->willReturnCallback(
			function (mixed ...$args) use ($answer): array {
				$this->asked[] = $args;
				return $answer;
			}
		);
		$guard->method('recordShipped')->willReturnCallback(
			function (mixed ...$args): bool {
				$this->recorded[] = [$args, count($this->written)];
				return true;
			}
		);

		return $guard;
	}//end guard()

	/**
	 * The handler under test.
	 *
	 * @param ShippedConfigurationGuard|null $guard The guard, null for none wired.
	 *
	 * @return ImportHandler
	 */
	private function handler(?ShippedConfigurationGuard $guard): ImportHandler {
		return new ImportHandler(
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->logger,
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class),
			shippedGuard: $guard
		);
	}//end handler()

	/**
	 * An incoming definition, newer than the stored one.
	 *
	 * @return array<string, mixed>
	 */
	private function incoming(): array {
		return ['slug' => 'page', 'title' => 'Page', 'version' => '2.0.0', 'properties' => ['new' => ['type' => 'string']]];
	}//end incoming()

	/**
	 * The guard is asked about the parts the import carries, and told not to record.
	 *
	 * @return void
	 */
	public function testTheGuardIsAskedAboutThePartsTheImportCarries(): void {
		$this->schemaExists();
		$answer = ['guarded' => false, 'baseline' => null, 'definition' => [], 'conflicts' => []];

		$this->handler(guard: $this->guard(answer: $answer))->importSchema(data: $this->incoming(), slugsAndIdsMap: [], appId: 'app');

		$this->assertCount(1, $this->asked);
		$this->assertSame('page', $this->asked[0][0]);
		$this->assertSame(
			['properties' => ['live' => ['type' => 'string']], 'required' => ['live'], 'authorization' => []],
			$this->asked[0][1]
		);
		$this->assertSame(['properties'], array_keys($this->asked[0][2]));
		$this->assertArrayHasKey('new', $this->asked[0][2]['properties']);
		$this->assertSame('app', $this->asked[0][3]);
		$this->assertSame('', $this->asked[0][4], 'no version reads as empty');
		$this->assertFalse($this->asked[0][6], 'the guard does not record before the write');
	}//end testTheGuardIsAskedAboutThePartsTheImportCarries()

	/**
	 * An unguarded answer writes the incoming definition and records its baseline after the write.
	 *
	 * @return void
	 */
	public function testAnUnguardedAnswerWritesTheIncomingDefinition(): void {
		$this->schemaExists();
		$answer = ['guarded' => false, 'baseline' => ['properties' => ['b' => 1]], 'definition' => ['properties' => ['ignored' => 1]], 'conflicts' => []];

		$this->handler(guard: $this->guard(answer: $answer))->importSchema(data: $this->incoming(), slugsAndIdsMap: [], appId: 'app', version: '3.0.0');

		$this->assertArrayHasKey('new', $this->written[0]['properties']);
		$this->assertSame('3.0.0', $this->asked[0][4]);
		$this->assertSame([[['page', ['properties' => ['b' => 1]], 'app', '3.0.0'], 1]], $this->recorded);
	}//end testAnUnguardedAnswerWritesTheIncomingDefinition()

	/**
	 * A guarded answer replaces the guarded parts only, and adds a part the import did not carry.
	 *
	 * @return void
	 */
	public function testAGuardedAnswerReplacesOnlyTheGuardedParts(): void {
		$this->schemaExists();
		$answer = [
			'guarded' => true,
			'baseline' => ['required' => []],
			'definition' => ['required' => ['kept'], 'authorization' => ['read' => ['group']], 'title' => 'Not a guarded part'],
			'conflicts' => [],
		];

		$handler = $this->handler(guard: $this->guard(answer: $answer));
		$handler->importSchema(data: $this->incoming(), slugsAndIdsMap: [], appId: 'app');

		$this->assertSame(['kept'], $this->written[0]['required']);
		$this->assertSame(['read' => ['group']], $this->written[0]['authorization']);
		$this->assertSame('Page', $this->written[0]['title']);
		$this->assertArrayHasKey('new', $this->written[0]['properties'], 'a part the guard did not return stays incoming');
		$this->assertSame([], $handler->takeKeptLocalReport(), 'no conflict, nothing kept local');
		$this->assertCount(1, $this->recorded);
		$this->assertSame('', $this->recorded[0][0][3]);
	}//end testAGuardedAnswerReplacesOnlyTheGuardedParts()

	/**
	 * Conflicts are logged, and reported once.
	 *
	 * @return void
	 */
	public function testConflictsAreLoggedAndReportedOnce(): void {
		$this->schemaExists();
		$answer = [
			'guarded' => true,
			'baseline' => null,
			'definition' => ['properties' => ['kept' => ['type' => 'string']]],
			'conflicts' => [['path' => 'properties.a', 'other' => 1], ['path' => 'required']],
		];
		$this->logger->expects($this->once())->method('warning')->with(
			'[ImportHandler] schema kept its local definition for parts the app also changed',
			$this->callback(
				static fn (array $context): bool => $context['schema_slug'] === 'page'
					&& $context['conflicts'] === ['properties.a', 'required']
			)
		);

		$handler = $this->handler(guard: $this->guard(answer: $answer));
		$handler->importSchema(data: $this->incoming(), slugsAndIdsMap: [], appId: 'app');

		$this->assertSame([['slug' => 'page', 'paths' => ['properties.a', 'required']]], $handler->takeKeptLocalReport());
		$this->assertSame([], $handler->takeKeptLocalReport());
		$this->assertArrayHasKey('kept', $this->written[0]['properties']);
		$this->assertSame([], $this->recorded, 'a null baseline records nothing');
	}//end testConflictsAreLoggedAndReportedOnce()

	/**
	 * A new schema imported without an app leaves the guard out.
	 *
	 * @return void
	 */
	public function testAnImportWithoutAnAppLeavesTheGuardOut(): void {
		$this->schemaIsNew();
		$answer = ['guarded' => false, 'baseline' => ['a' => 1], 'definition' => [], 'conflicts' => []];

		$this->handler(guard: $this->guard(answer: $answer))->importSchema(data: $this->incoming(), slugsAndIdsMap: []);

		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->recorded);
		$this->assertCount(1, $this->written);
	}//end testAnImportWithoutAnAppLeavesTheGuardOut()

	/**
	 * An update without an app writes the incoming definition unasked.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAnAppLeavesTheGuardOut(): void {
		$this->schemaExists();
		$answer = ['guarded' => true, 'baseline' => ['a' => 1], 'definition' => ['properties' => []], 'conflicts' => []];

		$this->handler(guard: $this->guard(answer: $answer))->importSchema(data: $this->incoming(), slugsAndIdsMap: []);

		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->recorded);
		$this->assertArrayHasKey('new', $this->written[0]['properties']);
	}//end testAnUpdateWithoutAnAppLeavesTheGuardOut()

	/**
	 * A created schema records the shipped parts it carries.
	 *
	 * @return void
	 */
	public function testACreatedSchemaRecordsTheShippedParts(): void {
		$this->schemaIsNew();
		$answer = ['guarded' => false, 'baseline' => null, 'definition' => [], 'conflicts' => []];
		$data = ['authorization' => ['read' => ['group']]] + $this->incoming();

		$this->handler(guard: $this->guard(answer: $answer))->importSchema(data: $data, slugsAndIdsMap: [], appId: 'app', version: '3.0.0');

		$this->assertSame([], $this->asked, 'a new schema has nothing to guard');
		$this->assertCount(1, $this->recorded);
		[$args, $writes] = $this->recorded[0];
		$this->assertSame(1, $writes, 'recorded after the write');
		$this->assertSame('page', $args[0]);
		$this->assertSame(['properties', 'authorization'], array_keys($args[1]));
		$this->assertSame(['read' => ['group']], $args[1]['authorization']);
		$this->assertSame(['app', '3.0.0'], [$args[2], $args[3]]);
	}//end testACreatedSchemaRecordsTheShippedParts()

	/**
	 * Without a guard an update writes the incoming definition.
	 *
	 * @return void
	 */
	public function testWithoutAGuardTheIncomingDefinitionIsWritten(): void {
		$this->schemaExists();

		$this->handler(guard: null)->importSchema(data: $this->incoming(), slugsAndIdsMap: [], appId: 'app');

		$this->assertArrayHasKey('new', $this->written[0]['properties']);
	}//end testWithoutAGuardTheIncomingDefinitionIsWritten()
}//end class
