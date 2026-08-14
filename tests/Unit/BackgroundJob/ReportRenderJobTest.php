<?php

/**
 * Regression tests for ReportRenderJob dashboard enumeration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
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

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ReportRenderJob;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Reporting\ReportRenderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * openregister#2282 — `ReportRenderJob` rendered ZERO dashboards on every run,
 * silently, by two independent routes:
 *
 *   1. It called `MagicMapper::findAll(_rbac: …, _multitenancy: …)`. Neither
 *      parameter exists on that method. On PHP 8 an unknown named argument
 *      raises `\Error`, which the job's `catch (\Throwable)` swallowed into a
 *      warning.
 *   2. Even without the bogus arguments, `MagicMapper::findAll()` early-returns
 *      `[]` unless it is given `register:` AND `schema:`. The job passed the
 *      register id inside `filters` instead.
 *
 * These tests assert the CONSEQUENCE — that driving the job actually reaches the
 * renderer with a dashboard — rather than that any particular method was called
 * with any particular arguments.
 *
 * The `MagicMapper` double is a PHPUnit mock of the REAL class, so it inherits
 * the real parameter list by reflection. That is what makes the negative control
 * meaningful: the pre-fix call shape raises the same `Error: Unknown named
 * parameter $_rbac` against this double that it raises in production. A
 * hand-written stub shaped like the code under test could not have caught it.
 */
class ReportRenderJobTest extends TestCase {

	/**
	 * Reports register id used across the fixtures.
	 *
	 * @var int
	 */
	private const REGISTER_ID = 65;

	/**
	 * Dashboard schema id used across the fixtures.
	 *
	 * @var int
	 */
	private const SCHEMA_ID = 213;

	/**
	 * Build the `reports` register with one schema attached.
	 *
	 * @return Register
	 */
	private function reportsRegister(): Register {
		$register = new Register();
		$register->setId(self::REGISTER_ID);
		$register->setSchemas([self::SCHEMA_ID]);

		return $register;
	}//end reportsRegister()

	/**
	 * Build the `dashboard` schema.
	 *
	 * @return Schema
	 */
	private function dashboardSchema(): Schema {
		$schema = new Schema();
		$schema->setId(self::SCHEMA_ID);
		$schema->setSlug(slug: 'dashboard');

		return $schema;
	}//end dashboardSchema()

	/**
	 * Build a dashboard object that is unconditionally due for rendering.
	 *
	 * `schedule.active` is true with a positive interval and no
	 * `lastRenderedAt`, which `shouldRender()` treats as "never rendered → fire
	 * now". `delivery.channel` is deliberately NOT `files`/`both` so the test
	 * exercises enumeration + render without touching the Files backend.
	 *
	 * @return ObjectEntity
	 */
	private function dueDashboard(): ObjectEntity {
		$dashboard = new ObjectEntity();
		$dashboard->setId(1);
		$dashboard->setObject(
			[
				'titel' => 'Quarterly WOO report',
				'schedule' => [
					'active' => true,
					'intervalSec' => 86400,
				],
				'delivery' => [
					'format' => 'csv',
					'channel' => 'email',
				],
			]
		);

		return $dashboard;
	}//end dueDashboard()

	/**
	 * Drive `ReportRenderJob::run()` and return how many dashboards reached the
	 * renderer.
	 *
	 * @param MagicMapper $objectMapper Object mapper double.
	 *
	 * @return int Number of dashboards handed to ReportRenderService::render().
	 */
	private function runJobAndCountRenders(MagicMapper $objectMapper): int {
		$register = $this->reportsRegister();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('true');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findMultiple')->willReturn([$this->dashboardSchema()]);

		$renders = 0;

		$renderService = $this->createMock(ReportRenderService::class);
		$renderService->method('render')->willReturnCallback(
			function () use (&$renders): array {
				$renders++;
				return [
					'filename' => 'report.csv',
					'bytes' => 'a,b',
				];
			}
		);

		$job = new ReportRenderJob(
			$this->createMock(ITimeFactory::class),
			$appConfig,
			$renderService,
			$registerMapper,
			$schemaMapper,
			$objectMapper,
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class)
		);

		$run = new ReflectionMethod(ReportRenderJob::class, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		return $renders;
	}//end runJobAndCountRenders()

	/**
	 * POSITIVE CONTROL — the job must actually render a non-zero dashboard set.
	 *
	 * This is the assertion the defect broke: with the fix in place, a due
	 * dashboard sitting in the reports register reaches
	 * `ReportRenderService::render()`.
	 *
	 * @return void
	 */
	public function testJobRendersDashboardsInTheReportsRegister(): void {
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findAllInRegisterSchemaTable')
			->willReturn([$this->dueDashboard()]);

		// Faithful to the real method: findAll() early-returns [] when it is
		// not given both register: and schema:.
		$objectMapper->method('findAll')->willReturn([]);

		$this->assertSame(
			1,
			$this->runJobAndCountRenders(objectMapper: $objectMapper),
			'ReportRenderJob must render the dashboards in the reports register. '
			. 'Rendering zero is openregister#2282 — the job shipped silently '
			. 'producing nothing on every scheduled run.'
		);

	}//end testJobRendersDashboardsInTheReportsRegister()

	/**
	 * NEGATIVE CONTROL — the enumeration must go through the register+schema
	 * read, not the context-free one.
	 *
	 * `MagicMapper::findAll()` cannot enumerate the register on its own: without
	 * `register:`/`schema:` it early-returns `[]`. If a future change routes
	 * `loadDashboards()` back through `findAll()` — the exact regression #2282
	 * was — this double supplies dashboards ONLY via
	 * `findAllInRegisterSchemaTable()`, so the render count drops to zero and
	 * `testJobRendersDashboardsInTheReportsRegister` fails.
	 *
	 * This test pins the other half: a job that reads only through `findAll()`
	 * genuinely gets nothing.
	 *
	 * @return void
	 */
	public function testFindAllWithoutRegisterAndSchemaYieldsNoDashboards(): void {
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findAll')->willReturn([]);
		$objectMapper->method('findAllInRegisterSchemaTable')->willReturn([]);

		$this->assertSame(
			0,
			$this->runJobAndCountRenders(objectMapper: $objectMapper),
			'With no dashboards readable, the job must render nothing — this '
			. 'proves the positive control above is measuring the read, not a '
			. 'constant.'
		);

	}//end testFindAllWithoutRegisterAndSchemaYieldsNoDashboards()

	/**
	 * The named-argument contract that caused the fatal.
	 *
	 * `MagicMapper::findAll()` has no `$_rbac` / `$_multitenancy`. Passing them
	 * as named arguments is a PHP 8 `\Error`, not an `\Exception`. Asserting
	 * their ABSENCE keeps the pre-fix call shape from being reintroduced on the
	 * assumption that it works because `MagicMapper::find()` accepts them.
	 *
	 * @return void
	 */
	public function testMagicMapperFindAllHasNoRbacNamedParameters(): void {
		$names = [];
		foreach ((new ReflectionMethod(MagicMapper::class, 'findAll'))->getParameters() as $parameter) {
			$names[] = $parameter->getName();
		}

		$this->assertNotContains(
			'_rbac',
			$names,
			'MagicMapper::findAll() does not accept $_rbac. If this ever changes, '
			. 'the RBAC contract of the central object mapper changed with it — '
			. 'see openregister#2282 and revisit ReportRenderJob deliberately.'
		);
		$this->assertNotContains('_multitenancy', $names);

	}//end testMagicMapperFindAllHasNoRbacNamedParameters()

	/**
	 * The read path the fix uses must accept the arguments the job passes.
	 *
	 * @return void
	 */
	public function testRegisterSchemaReadAcceptsTheArgumentsTheJobPasses(): void {
		$names = [];
		foreach ((new ReflectionMethod(MagicMapper::class, 'findAllInRegisterSchemaTable'))->getParameters() as $p) {
			$names[] = $p->getName();
		}

		foreach (['register', 'schema', 'limit', 'offset', 'filters'] as $required) {
			$this->assertContains(
				$required,
				$names,
				sprintf('ReportRenderJob::loadDashboards() passes %s: by name.', $required)
			);
		}

	}//end testRegisterSchemaReadAcceptsTheArgumentsTheJobPasses()

}//end class
