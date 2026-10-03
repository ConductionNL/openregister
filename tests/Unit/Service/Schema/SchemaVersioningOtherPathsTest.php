<?php

declare(strict_types=1);

/**
 * The schema tool and the update-from-source merge classify, version and log
 * a definition change, like the schema API and the configuration import do
 * (openregister#4102).
 *
 * The versioning service is the real one over the real diff service; only its
 * mappers are doubles, so the classification and the version are the ones
 * production computes.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Controller\SchemaImportController;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Tool\SchemaTool;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Schema versioning on the tool and the merge path.
 */
class SchemaVersioningOtherPathsTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var SchemaChangelogMapper&MockObject */
	private SchemaChangelogMapper $changelogMapper;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	private Schema $stored;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->changelogMapper = $this->createMock(SchemaChangelogMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->stored = new Schema();
		$this->stored->setId(12);
		$this->stored->setUuid('schema-12');
		$this->stored->setTitle('Case');
		$this->stored->setVersion('1.0.0');
		$this->stored->setProperties(['title' => ['type' => 'string'], 'status' => ['type' => 'string']]);
		$this->stored->setRequired(['status']);
		$this->stored->setConfiguration(['importSource' => ['dialect' => 'schema.org', 'type' => 'Thing']]);

		$this->schemaMapper->method('find')->willReturn($this->stored);
		$this->schemaMapper->method('update')->willReturnArgument(0);
	}//end setUp()

	/**
	 * The real versioning service over the real diff service.
	 *
	 * @return SchemaVersioningService
	 */
	private function versioning(?LoggerInterface $logger = null): SchemaVersioningService {
		return new SchemaVersioningService(
			diffService: new SchemaDiffService(),
			changelogMapper: $this->changelogMapper,
			runMapper: $this->createMock(SchemaRunMapper::class),
			runEntryMapper: $this->createMock(SchemaRunEntryMapper::class),
			userSession: $this->userSession,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end versioning()

	/**
	 * An unacknowledged breaking change from an agent is recorded AND logged, never refused
	 * (Ruben, 29 Sep 2026: record and log, never refuse).
	 *
	 * @return void
	 */
	public function testAnAgentBreakingChangeIsLoggedNotRefused(): void {
		$this->changelogMapper->method('createFromArray')->willReturn(new SchemaChangelog());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('breaking'),
				$this->callback(static fn (array $c): bool => $c['schema_id'] === 12 && $c['origin'] === 'agent tool' && $c['version'] === '2.0.0')
			);

		$tool = new SchemaTool($this->userSession, $this->createMock(LoggerInterface::class), $this->schemaMapper, $this->versioning(logger: $logger));
		$result = $tool->updateSchema(id: '12', properties: ['title' => ['type' => 'string']], required: []);

		$this->assertSame('2.0.0', $result['data']['version']);
	}//end testAnAgentBreakingChangeIsLoggedNotRefused()

	/**
	 * A breaking change a person acknowledged is recorded without a warning.
	 *
	 * @return void
	 */
	public function testAnAcknowledgedBreakingChangeIsNotWarnedAbout(): void {
		$this->changelogMapper->method('createFromArray')->willReturn(new SchemaChangelog());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$changeSet = new \OCA\OpenRegister\Service\Schema\SchemaChangeSet(
			changes: [['type' => 'property_removed', 'property' => 'status']],
			classification: 'breaking',
			bump: 'major'
		);
		$this->versioning(logger: $logger)->recordChangelog(schemaId: 12, version: '2.0.0', changeSet: $changeSet, acknowledged: true);
	}//end testAnAcknowledgedBreakingChangeIsNotWarnedAbout()

	/**
	 * The schema tool dropping a required property is recorded as breaking with a major bump.
	 *
	 * @return void
	 */
	public function testTheSchemaToolRecordsABreakingChange(): void {
		$this->changelogMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(static fn (array $e): bool => $e['schemaId'] === 12 && $e['classification'] === 'breaking' && $e['version'] === '2.0.0'))
			->willReturn(new SchemaChangelog());

		$tool = new SchemaTool($this->userSession, $this->createMock(LoggerInterface::class), $this->schemaMapper, $this->versioning());
		$result = $tool->updateSchema(id: '12', properties: ['title' => ['type' => 'string']], required: []);

		$this->assertSame('2.0.0', $result['data']['version']);
	}//end testTheSchemaToolRecordsABreakingChange()

	/**
	 * A title-only edit through the tool is not a definition change and records nothing.
	 *
	 * @return void
	 */
	public function testATitleEditThroughTheToolRecordsNothing(): void {
		$this->changelogMapper->expects($this->never())->method('createFromArray');

		$tool = new SchemaTool($this->userSession, $this->createMock(LoggerInterface::class), $this->schemaMapper, $this->versioning());
		$result = $tool->updateSchema(id: '12', title: 'Case, renamed');

		$this->assertSame('1.0.0', $result['data']['version']);
	}//end testATitleEditThroughTheToolRecordsNothing()

	/**
	 * An applied update-from-source merge that adds a property is a compatible minor bump, recorded.
	 *
	 * @return void
	 */
	public function testAnAppliedMergeIsClassifiedAndRecorded(): void {
		$merged = ['title' => ['type' => 'string'], 'status' => ['type' => 'string'], 'note' => ['type' => 'string']];

		$importService = $this->createMock(SchemaImportService::class);
		$importService->method('previewUpdateFromSource')->willReturn(
			[
				'added' => ['note'],
				'removed' => [],
				'changed' => [],
				'keptLocal' => [],
				'conflicts' => [],
				'applied' => true,
				'merged' => $merged,
			]
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'apply' ? 'true' : $default)
		);

		$this->changelogMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(static fn (array $e): bool => $e['classification'] === 'compatible' && $e['version'] === '1.1.0'))
			->willReturn(new SchemaChangelog());

		$controller = new SchemaImportController(
			'openregister',
			$request,
			$importService,
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->versioning()
		);

		$response = $controller->reimport(12);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('1.1.0', $this->stored->getVersion());
		$this->assertSame($merged, $this->stored->getProperties());
	}//end testAnAppliedMergeIsClassifiedAndRecorded()
}//end class
