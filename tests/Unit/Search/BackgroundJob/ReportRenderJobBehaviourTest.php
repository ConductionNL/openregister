<?php

/**
 * ReportRenderJob decides which dashboards render and where the bytes land.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\BackgroundJob;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\BackgroundJob\ReportRenderJob;
use OCA\OpenRegister\Search\BackgroundJob\Support\ReportRenderDelivery;
use OCA\OpenRegister\Search\BackgroundJob\Support\ReportRenderPlatform;
use OCA\OpenRegister\Search\BackgroundJob\Support\ReportRenderSources;
use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Branches of the scheduled-report job: kill switch, schedule gate, format
 * fallback, delivery channel, owner and path guards, and the Files write.
 */
class ReportRenderJobBehaviourTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private RegisterMapperInterface&MockObject $registerMapper;
	private SchemaMapperInterface&MockObject $schemaMapper;
	private MagicMapperInterface&MockObject $objectMapper;
	private ReportRenderService&MockObject $renderService;
	private IRootFolder&MockObject $rootFolder;
	private LoggerInterface&MockObject $logger;

	/**
	 * Messages the logger received, keyed by level.
	 *
	 * @var array<string, list<array{0: string, 1: array}>>
	 */
	private array $logged = ['info' => [], 'warning' => []];

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('true');
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->objectMapper = $this->createMock(MagicMapperInterface::class);
		$this->renderService = $this->createMock(ReportRenderService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[$level][] = [$message, $context];
				}
			);
		}
	}

	private function runJob(): void {
		$job = new ReportRenderJob(
			$this->createMock(ITimeFactory::class),
			new ReportRenderSources(new \OCA\OpenRegister\Db\SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->objectMapper)),
			new ReportRenderDelivery($this->renderService, $this->rootFolder),
			new ReportRenderPlatform($this->appConfig, $this->logger)
		);
		(new ReflectionMethod(ReportRenderJob::class, 'run'))->invoke($job, null);
	}

	/**
	 * Wire one register with one schema whose table holds the given dashboards.
	 *
	 * @param list<ObjectEntity> $dashboards The dashboards in the table.
	 */
	private function withDashboards(array $dashboards): void {
		$register = new Register();
		$register->setId(65);
		$register->setSchemas([213]);
		$this->registerMapper->method('find')->with('reports', false, false)->willReturn($register);

		$schema = new Schema();
		$schema->setId(213);
		$this->schemaMapper->method('findMultiple')->with([213], false, false)->willReturn([$schema]);
		$this->objectMapper->method('findAllInRegisterSchemaTable')->willReturn($dashboards);
	}

	private function dashboard(array $object, ?string $owner = 'alice'): ObjectEntity {
		$dashboard = new ObjectEntity();
		$dashboard->setUuid('dash-uuid');
		$dashboard->setOwner($owner);
		$dashboard->setObject($object);

		return $dashboard;
	}

	private function due(array $delivery, array $extra = []): array {
		return array_merge(
			['titel' => 'Quarterly WOO Report', 'schedule' => ['active' => true, 'intervalSec' => 3600], 'delivery' => $delivery],
			$extra
		);
	}

	/**
	 * The `rendered` and `skipped` counts of the pass-complete log line.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function passCounts(): array {
		foreach ($this->logged['info'] as [$message, $context]) {
			if (str_contains($message, 'pass complete') === true) {
				return [$context['rendered'], $context['skipped']];
			}
		}

		$this->fail('No pass-complete log line.');
	}

	public function testKillSwitchStopsBeforeAnyRead(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueString')
			->with('openregister', 'rapportage_scheduled_renders_enabled', 'true')
			->willReturn('false');
		$this->appConfig = $appConfig;
		$this->registerMapper->expects($this->never())->method('find');

		$this->runJob();

		$this->assertStringContainsString('kill switch on', $this->logged['info'][0][0]);
	}

	public function testMissingReportsRegisterSkipsThePass(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->schemaMapper->expects($this->never())->method('findMultiple');

		$this->runJob();

		$this->assertCount(1, $this->logged['info']);
		$this->assertStringContainsString('No `reports` register found', $this->logged['info'][0][0]);
		$this->assertSame('none', $this->logged['info'][0][1]['error']);
	}

	public function testRegisterWithoutSchemasRendersNothing(): void {
		$register = new Register();
		$register->setId(65);
		$register->setSchemas([]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('findMultiple')->willReturn([]);
		$this->objectMapper->expects($this->never())->method('findAllInRegisterSchemaTable');

		$this->runJob();

		$this->assertStringContainsString('no resolvable schemas', $this->logged['warning'][0][0]);
		$this->assertSame([0, 0], $this->passCounts());
	}

	public function testSchemaEnumerationFailureIsLoggedAndOtherSchemasStillRead(): void {
		$register = new Register();
		$register->setId(65);
		$register->setSchemas([1, 2]);
		$this->registerMapper->method('find')->willReturn($register);
		$first = new Schema();
		$first->setId(1);
		$second = new Schema();
		$second->setId(2);
		$this->schemaMapper->method('findMultiple')->willReturn([$first, $second]);

		$dashboard = $this->dashboard($this->due(['channel' => 'email']));
		$this->objectMapper->expects($this->exactly(2))->method('findAllInRegisterSchemaTable')
			->willReturnCallback(
				static function (RegisterSchema $table, ListQuery $listing) use ($dashboard): array {
					[$r, $schema, $limit, $offset, $filters] = [$table->register, $table->schema, $listing->limitOrNull(), $listing->offset, ($listing->filters === [] ? null : $listing->filters)];
					if ($schema->getId() === 1) {
						throw new RuntimeException('table missing');
					}
					return [$dashboard];
				}
			);
		$this->renderService->expects($this->once())->method('render')->willReturn(['filename' => 'r.csv', 'bytes' => 'x']);

		$this->runJob();

		$this->assertStringContainsString('Failed to enumerate dashboards', $this->logged['warning'][0][0]);
		$this->assertSame(1, $this->logged['warning'][0][1]['schemaId']);
		$this->assertSame([1, 0], $this->passCounts());
	}

	public function testDashboardsReadWithRbacAndMultitenancyDisabled(): void {
		$register = new Register();
		$register->setId(65);
		$register->setSchemas([213]);
		$this->registerMapper->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(213);
		$this->schemaMapper->method('findMultiple')->willReturn([$schema]);
		$this->objectMapper->expects($this->once())->method('findAllInRegisterSchemaTable')
			->with(new RegisterSchema($register, $schema), new ListQuery(200, 0, ['_rbac' => false, '_multitenancy' => false]))
			->willReturn([]);

		$this->runJob();

		$this->assertSame([0, 0], $this->passCounts());
	}

	/**
	 * @return array<string, array{0: array}>
	 */
	public static function notDueProvider(): array {
		return [
			'no schedule' => [['titel' => 'a']],
			'schedule not an array' => [['schedule' => 'daily']],
			'inactive' => [['schedule' => ['active' => false, 'intervalSec' => 60]]],
			'zero interval' => [['schedule' => ['active' => true, 'intervalSec' => 0]]],
			'rendered within the interval' => [
				['schedule' => ['active' => true, 'intervalSec' => 3600], 'lastRenderedAt' => '2999-01-01T00:00:00+00:00'],
			],
		];
	}

	/**
	 * @dataProvider notDueProvider
	 */
	public function testDashboardsThatAreNotDueAreSkipped(array $object): void {
		$this->withDashboards([$this->dashboard($object)]);
		$this->renderService->expects($this->never())->method('render');

		$this->runJob();

		$this->assertSame([0, 1], $this->passCounts());
	}

	/**
	 * @return array<string, array{0: array}>
	 */
	public static function dueProvider(): array {
		return [
			'never rendered' => [[]],
			'empty lastRenderedAt' => [['lastRenderedAt' => '']],
			'interval elapsed' => [['lastRenderedAt' => '2000-01-01T00:00:00+00:00']],
			'malformed lastRenderedAt' => [['lastRenderedAt' => 'not a date at all']],
		];
	}

	/**
	 * @dataProvider dueProvider
	 */
	public function testDueDashboardsAreRendered(array $extra): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'email', 'format' => 'pdf'], $extra))]);
		$this->renderService->expects($this->once())->method('render')
			->with($this->isInstanceOf(ObjectEntity::class), 'pdf')
			->willReturn(['filename' => 'r.pdf', 'bytes' => 'x']);
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->runJob();

		$this->assertSame([1, 0], $this->passCounts());
	}

	public function testUnknownFormatFallsBackToXlsx(): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'email', 'format' => 'docx']))]);
		$this->renderService->expects($this->once())->method('render')
			->with($this->anything(), 'xlsx')
			->willReturn(['filename' => 'r.xlsx', 'bytes' => 'x']);

		$this->runJob();
	}

	public function testRenderFailureIsLoggedAndNotCounted(): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'email']))]);
		$this->renderService->method('render')->willThrowException(new RuntimeException('boom'));

		$this->runJob();

		$this->assertSame('Quarterly WOO Report', $this->logged['warning'][0][1]['dashboard']);
		$this->assertSame('boom', $this->logged['warning'][0][1]['error']);
		$this->assertSame([0, 0], $this->passCounts());
	}

	public function testRenderFailureWithoutTitleNamesTheUuid(): void {
		$this->withDashboards([$this->dashboard(['schedule' => ['active' => true, 'intervalSec' => 60]])]);
		$this->renderService->method('render')->willThrowException(new RuntimeException('boom'));

		$this->runJob();

		$this->assertSame('dash-uuid', $this->logged['warning'][0][1]['dashboard']);
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function missingOwnerProvider(): array {
		return ['null owner' => [null], 'empty owner' => ['']];
	}

	/**
	 * @dataProvider missingOwnerProvider
	 */
	public function testFilesDeliveryWithoutOwnerIsRefused(?string $owner): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'files']), $owner)]);
		$this->renderService->method('render')->willReturn(['filename' => 'r.xlsx', 'bytes' => 'x']);
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->runJob();

		$this->assertStringContainsString('owner missing', $this->logged['warning'][0][0]);
	}

	public function testMissingUserFolderSkipsDelivery(): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'both']))]);
		$this->renderService->method('render')->willReturn(['filename' => 'r.xlsx', 'bytes' => 'x']);
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('alice')
			->willThrowException(new NotFoundException('gone'));

		$this->runJob();

		$this->assertStringContainsString('User folder unavailable', $this->logged['warning'][0][0]);
		$this->assertSame([1, 0], $this->passCounts());
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function rejectedFolderProvider(): array {
		return ['traversal' => ['Reports/../../etc'], 'only slashes' => ['///']];
	}

	/**
	 * @dataProvider rejectedFolderProvider
	 */
	public function testUnsafeDeliveryFolderIsRejected(string $folder): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'files', 'filesFolder' => $folder]))]);
		$this->renderService->method('render')->willReturn(['filename' => 'r.xlsx', 'bytes' => 'x']);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->never())->method('newFolder');
		$userFolder->expects($this->never())->method('get');
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->runJob();

		$this->assertStringContainsString('path traversal', $this->logged['warning'][0][0]);
	}

	public function testDefaultFolderIsCreatedFromTheTitleSlugAndANewFileWritten(): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'files']))]);
		$this->renderService->method('render')->willReturn(['filename' => 'r.xlsx', 'bytes' => 'BYTES']);

		$target = $this->createMock(Folder::class);
		$target->method('nodeExists')->with('r.xlsx')->willReturn(false);
		$target->expects($this->once())->method('newFile')->with('r.xlsx', 'BYTES');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->with('Reports/quarterly-woo-report')->willReturn(false);
		$userFolder->expects($this->once())->method('newFolder')->with('Reports/quarterly-woo-report');
		$userFolder->method('get')->with('Reports/quarterly-woo-report')->willReturn($target);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$this->runJob();

		$this->assertSame([], $this->logged['warning']);
	}

	public function testTitleWithoutSlugCharactersUsesDashboardAndAnExistingFileIsOverwritten(): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'files'], ['titel' => ' !!! ']))]);
		$this->renderService->method('render')->willReturn(['filename' => 'r.xlsx', 'bytes' => 'NEW']);

		$existing = $this->createMock(File::class);
		$existing->expects($this->once())->method('putContent')->with('NEW');
		$target = $this->createMock(Folder::class);
		$target->method('nodeExists')->willReturn(true);
		$target->method('get')->with('r.xlsx')->willReturn($existing);
		$target->expects($this->never())->method('newFile');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->with('Reports/dashboard')->willReturn(true);
		$userFolder->expects($this->never())->method('newFolder');
		$userFolder->method('get')->with('Reports/dashboard')->willReturn($target);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->runJob();
	}

	public function testConfiguredFolderIsMadeRelativeToTheOwnerHome(): void {
		$this->withDashboards([$this->dashboard($this->due(['channel' => 'files', 'filesFolder' => '/Exports/woo']))]);
		$this->renderService->method('render')->willReturn(['filename' => 'r.xlsx', 'bytes' => 'B']);

		$target = $this->createMock(Folder::class);
		$target->method('nodeExists')->willReturn(false);
		$target->expects($this->once())->method('newFile')->with('r.xlsx', 'B');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->with('Exports/woo')->willReturn(true);
		$userFolder->expects($this->once())->method('get')->with('Exports/woo')->willReturn($target);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->runJob();
	}

	public function testDashboardWithoutDeliveryRendersXlsxIntoFiles(): void {
		$this->withDashboards([$this->dashboard(['titel' => 'T', 'schedule' => ['active' => 'yes', 'intervalSec' => '60']])]);
		$this->renderService->expects($this->once())->method('render')->with($this->anything(), 'xlsx')
			->willReturn(['filename' => 'r.xlsx', 'bytes' => 'B']);

		$target = $this->createMock(Folder::class);
		$target->expects($this->once())->method('newFile')->with('r.xlsx', 'B');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->with('Reports/t')->willReturn($target);
		$this->rootFolder->expects($this->once())->method('getUserFolder')->willReturn($userFolder);

		$this->runJob();
	}
}
