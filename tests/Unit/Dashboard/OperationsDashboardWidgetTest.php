<?php

/**
 * The operations widget on the Nextcloud dashboard (production-observability, "Dashboard widget shows key metrics").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Dashboard
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Dashboard;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Dashboard\OperationsDashboardWidget;
use OCA\OpenRegister\Service\Observability\ObjectCountMetricsProvider;
use OCA\OpenRegister\Service\Observability\RequestAlertEvaluator;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Dashboard\OperationsDashboardWidget
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class OperationsDashboardWidgetTest extends TestCase {

	/**
	 * Rows per counted table; a missing table throws.
	 *
	 * @var array<string, int>
	 */
	private array $rows = ['openregister_registers' => 3, 'openregister_schemas' => 12];

	/**
	 * The object samples.
	 *
	 * @var array<int, array{labels: array<string, string>, value: int}>|null
	 */
	private ?array $objectSamples = [
		['labels' => ['register' => '1', 'schema' => '5'], 'value' => 500],
		['labels' => ['register' => '1', 'schema' => '6'], 'value' => 200],
	];

	/**
	 * The last window, or null.
	 *
	 * @var array{minutes: int, requests: int, errors: int, averageMs: int|null}|null
	 */
	private ?array $window = ['minutes' => 5, 'requests' => 120, 'errors' => 4, 'averageMs' => 85];

	public function testAnAdministratorSeesObjectsRegistersSchemasErrorsAndResponseTime(): void {
		$items = $this->widget()->getItemsV2('admin')->getItems();

		$this->assertSame(
			[
				['700', 'Objects', 'https://nc/apps/openregister/'],
				['3', 'Registers', 'https://nc/apps/openregister/'],
				['12', 'Schemas', 'https://nc/apps/openregister/'],
				['4', 'Server errors in the last 5 minutes', 'https://nc/settings/admin/openregister'],
				['85 ms', 'Average response time in the last 5 minutes', 'https://nc/apps/openregister/'],
			],
			array_map(static fn (WidgetItem $item): array => [$item->getTitle(), $item->getSubtitle(), $item->getLink()], $items)
		);
	}//end testAnAdministratorSeesObjectsRegistersSchemasErrorsAndResponseTime()

	public function testAWindowWithoutRequestsSaysSo(): void {
		$this->window = ['minutes' => 5, 'requests' => 0, 'errors' => 0, 'averageMs' => null];

		$items = $this->widget()->getItemsV2('admin')->getItems();

		$this->assertSame('no requests', $items[4]->getTitle());
	}//end testAWindowWithoutRequestsSaysSo()

	public function testFiguresThatCannotBeReadAreLeftOut(): void {
		$this->window = null;
		$this->objectSamples = null;
		unset($this->rows['openregister_schemas']);

		$items = $this->widget()->getItemsV2('admin')->getItems();

		$this->assertSame(['Registers'], array_map(static fn (WidgetItem $item): string => $item->getSubtitle(), $items));
	}//end testFiguresThatCannotBeReadAreLeftOut()

	public function testANonAdministratorGetsNoFigures(): void {
		$widget = $this->widget();

		$items = $widget->getItemsV2('bob');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Only administrators see the OpenRegister operations figures.', $items->getEmptyContentMessage());
	}//end testANonAdministratorGetsNoFigures()

	public function testTheWidgetIsEnabledForAnAdministratorOnly(): void {
		$this->assertTrue($this->widget(viewer: 'admin')->isEnabled());
		$this->assertFalse($this->widget(viewer: 'bob')->isEnabled());
		$this->assertFalse($this->widget(viewer: null)->isEnabled());
	}//end testTheWidgetIsEnabledForAnAdministratorOnly()

	public function testIdentity(): void {
		$widget = $this->widget();

		$this->assertSame('openregister-operations', $widget->getId());
		$this->assertSame('OpenRegister operations', $widget->getTitle());
		$this->assertSame('https://nc/apps/openregister/', $widget->getUrl());
		$this->assertSame('https://nc/apps/openregister/img/app-dark.svg', $widget->getIconUrl());
	}//end testIdentity()

	private function widget(?string $viewer = 'admin'): OperationsDashboardWidget {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$urls = $this->createMock(IURLGenerator::class);
		$links = [
			'settings.AdminSettings.index' => 'https://nc/settings/admin/openregister',
			'openregister.dashboard.page' => 'https://nc/apps/openregister/',
		];
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route): string => $links[$route]);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => '/apps/'.$app.'/img/'.$file);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc'.$path);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($viewer !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($viewer);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$objects = $this->createMock(ObjectCountMetricsProvider::class);
		if ($this->objectSamples === null) {
			$objects->method('metrics')->willThrowException(new RuntimeException('db down'));
		} else {
			$objects->method('metrics')->willReturn([new MetricSample(name: 'objects_total', type: 'gauge', help: 'h', samples: $this->objectSamples)]);
		}

		$windows = $this->createMock(RequestAlertEvaluator::class);
		$windows->method('lastWindow')->willReturn($this->window);

		return new OperationsDashboardWidget(
			l10n: $l10n,
			urls: $urls,
			session: $session,
			groups: $groups,
			db: $this->db(),
			objects: $objects,
			windows: $windows,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end widget()

	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				$table = '';
				$func = $this->createMock(IFunctionBuilder::class);
				$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
				$qb = $this->createMock(IQueryBuilder::class);
				$qb->method('func')->willReturn($func);
				$qb->method('select')->willReturnSelf();
				$qb->method('from')->willReturnCallback(
					function (string $from) use (&$table, $qb): IQueryBuilder {
						$table = $from;
						return $qb;
					}
				);
				$qb->method('executeQuery')->willReturnCallback(
					function () use (&$table): IResult {
						if (isset($this->rows[$table]) === false) {
							throw new RuntimeException('no table');
						}

						$result = $this->createMock(IResult::class);
						$result->method('fetchOne')->willReturn((string) $this->rows[$table]);
						return $result;
					}
				);
				return $qb;
			}
		);

		return $db;
	}//end db()
}//end class
