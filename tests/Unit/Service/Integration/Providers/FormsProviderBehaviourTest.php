<?php

/**
 * The NC Forms leaf on an object: linked forms merged with their upstream
 * row, submissions rolled up per form, orphan submissions listed under their
 * own form, the status a form's state and expiry decide, and the marker
 * fallback when nothing is linked.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\Providers\FormsProvider;
use OCA\OpenRegister\Service\Integration\Providers\LeafMarkerScanning;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * FormsProvider.
 */
class FormsProviderBehaviourTest extends TestCase {

	private const FAR_PAST = 1000;

	private const FAR_FUTURE = 4102444800;

	/**
	 * Upstream form rows by form id; a Throwable makes that query fail.
	 *
	 * @var array<int, array<string, mixed>|\Throwable>
	 */
	private array $forms = [];

	/**
	 * Submission counts by form id; a Throwable makes that query fail.
	 *
	 * @var array<int, int|\Throwable>
	 */
	private array $counts = [];

	/**
	 * Rows the marker scan returns, or a Throwable.
	 *
	 * @var list<array<string, mixed>>|\Throwable
	 */
	private array|\Throwable $markerRows = [];

	/**
	 * Queries run, as "table:parameter".
	 *
	 * @var list<string>
	 */
	private array $queries = [];

	private function provider(bool $installed, array|\Throwable $links = []): FormsProvider {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('forms')->willReturn($installed);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$mapper = $this->createMock(FormLinkMapperInterface::class);
		if ($links instanceof \Throwable) {
			$mapper->method('findByObjectUuid')->willThrowException($links);
		} else {
			$mapper->method('findByObjectUuid')->with('obj-1')->willReturn($links);
		}

		return new FormsProvider(new LeafMarkerScanning($this->db(), $apps, $l10n, $this->createMock(LoggerInterface::class)), $mapper);
	}//end provider()

	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		return $db;
	}//end db()

	private function queryBuilder(): IQueryBuilder {
		$state = ['table' => '', 'param' => null];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'where'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('from')->willReturnCallback(
			function (string $table) use (&$state, $qb): IQueryBuilder {
				$state['table'] = $table;
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$state): string {
				$state['param'] = $value;
				return ':param';
			}
		);
		$qb->method('createFunction')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state): IResult {
				$this->queries[] = $state['table'].':'.$state['param'];
				return $this->queryResult(table: $state['table'], param: $state['param']);
			}
		);
		return $qb;
	}//end queryBuilder()

	private function queryResult(string $table, mixed $param): IResult {
		$rows = [];
		$one = 0;
		if ($table === 'forms_v2_forms' && is_int($param) === true) {
			$row = ($this->forms[$param] ?? null);
			if ($row instanceof \Throwable) {
				throw $row;
			}
			$rows = ($row === null ? [] : [$row]);
		} else if ($table === 'forms_v2_forms') {
			if ($this->markerRows instanceof \Throwable) {
				throw $this->markerRows;
			}
			$rows = $this->markerRows;
		} else if ($table === 'forms_v2_submissions') {
			$one = ($this->counts[$param] ?? 0);
			if ($one instanceof \Throwable) {
				throw $one;
			}
		}

		$result = $this->createMock(IResult::class);
		$rows[] = false;
		$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
		$result->method('fetchOne')->willReturn($one);
		return $result;
	}//end queryResult()

	private function link(int $formId, ?int $submissionId = null, string $linkedAt = '2026-01-01T00:00:00+00:00'): FormLink {
		$link = new FormLink();
		$link->setFormId($formId);
		$link->setFormHash('hash-'.$formId);
		$link->setSubmissionId($submissionId);
		$link->setTitle('Linked '.$formId);
		$link->setLinkedAt(new DateTime($linkedAt));
		return $link;
	}//end link()

	public function testItDescribesItselfAndItsHealthFollowsTheFormsApp(): void {
		$this->assertSame(['forms', 'Forms', 'ClipboardText', 'workflow'], [
			$this->provider(true)->getId(),
			$this->provider(true)->getLabel(),
			$this->provider(true)->getIcon(),
			$this->provider(true)->getGroup(),
		]);
		$this->assertSame(['status' => 'ok', 'authStatus' => 'configured', 'message' => null], $this->provider(true)->health());
		$this->assertSame(
			['status' => 'unavailable', 'authStatus' => 'configured', 'message' => 'NC Forms app is not installed'],
			$this->provider(false)->health()
		);
	}//end testItDescribesItselfAndItsHealthFollowsTheFormsApp()

	public function testWithoutTheFormsAppNothingIsListedOrQueried(): void {
		$this->assertSame([], $this->provider(false, [$this->link(1)])->list('r', 's', 'obj-1'));
		$this->assertSame([], $this->queries);
	}//end testWithoutTheFormsAppNothingIsListedOrQueried()

	public function testALinkedFormIsMergedWithItsUpstreamRowAndRollsUpItsSubmissions(): void {
		$this->forms[1] = ['id' => 1, 'hash' => 'up-hash', 'title' => 'Upstream title', 'description' => 'Desc', 'expires' => self::FAR_FUTURE, 'state' => 0];
		$this->counts[1] = 1;
		$links = [
			$this->link(1),
			$this->link(1, 10, '2026-02-01T00:00:00+00:00'),
			$this->link(1, 11, '2026-03-01T00:00:00+00:00'),
			$this->link(1, 12, '2026-01-15T00:00:00+00:00'),
		];

		$rows = $this->provider(true, $links)->list('r', 's', 'obj-1');

		$this->assertCount(1, $rows);
		$this->assertSame('1', $rows[0]['id']);
		$this->assertSame('Upstream title', $rows[0]['title']);
		$this->assertSame('Desc', $rows[0]['description']);
		$this->assertSame('/index.php/apps/forms/up-hash', $rows[0]['url']);
		$this->assertSame('open', $rows[0]['status']);
		$this->assertTrue($rows[0]['accessible']);
		$this->assertSame(gmdate(DATE_ATOM, self::FAR_FUTURE), $rows[0]['expiresAt']);
		$this->assertSame(3, $rows[0]['submissionCount']);
		$this->assertSame((new DateTime('2026-03-01T00:00:00+00:00'))->getTimestamp(), $rows[0]['lastUpdated']);
		$this->assertSame('up-hash', $rows[0]['data']['hash']);
	}//end testALinkedFormIsMergedWithItsUpstreamRowAndRollsUpItsSubmissions()

	public function testTheUpstreamCountWinsWhenItExceedsTheLinkedSubmissions(): void {
		$this->forms[1] = ['id' => 1, 'hash' => 'h', 'title' => 'T', 'description' => null, 'expires' => 0, 'state' => 0];
		$this->counts[1] = 7;

		$rows = $this->provider(true, [$this->link(1), $this->link(1, 10)])->list('r', 's', 'obj-1');

		$this->assertSame(7, $rows[0]['submissionCount']);
		$this->assertNull($rows[0]['expiresAt']);
		$this->assertSame('', $rows[0]['description']);
	}//end testTheUpstreamCountWinsWhenItExceedsTheLinkedSubmissions()

	public function testAFormWithoutSubmissionLinksHasNoLastUpdated(): void {
		$this->forms[1] = ['id' => 1, 'hash' => 'h', 'title' => 'T', 'description' => 'D', 'expires' => 0, 'state' => 0];

		$rows = $this->provider(true, [$this->link(1)])->list('r', 's', 'obj-1');

		$this->assertArrayNotHasKey('lastUpdated', $rows[0]);
		$this->assertSame(0, $rows[0]['submissionCount']);
	}//end testAFormWithoutSubmissionLinksHasNoLastUpdated()

	public function testAnOrphanSubmissionIsListedFromItsLinkWhenTheFormIsGoneUpstream(): void {
		$this->forms[2] = new RuntimeException('no table');
		$this->counts[2] = new RuntimeException('no table');
		$orphan = $this->link(2, 20);
		$orphan->setStatus('archived');
		$orphan->setExpiresAt(new DateTime('@'.self::FAR_FUTURE));
		$this->forms[3] = ['id' => 3, 'hash' => 'h3', 'title' => 'Three', 'description' => 'D', 'expires' => 0, 'state' => 0];

		$rows = $this->provider(true, [$this->link(3), $orphan])->list('r', 's', 'obj-1');

		$this->assertSame(['3', '2'], array_column($rows, 'id'));
		$this->assertSame('Linked 2', $rows[1]['title']);
		$this->assertSame('/index.php/apps/forms/hash-2', $rows[1]['url']);
		$this->assertSame('archived', $rows[1]['status']);
		$this->assertFalse($rows[1]['accessible']);
		$this->assertSame(gmdate(DATE_ATOM, self::FAR_FUTURE), $rows[1]['expiresAt']);
		$this->assertSame(1, $rows[1]['submissionCount']);
	}//end testAnOrphanSubmissionIsListedFromItsLinkWhenTheFormIsGoneUpstream()

	public function testALinkedFormMissingUpstreamFallsBackToTheLinkStatus(): void {
		$draft = $this->link(4);
		$draft->setStatus('draft');
		$open = $this->link(5);
		$open->setStatus('published');

		$rows = $this->provider(true, [$draft, $open])->list('r', 's', 'obj-1');

		$this->assertSame(['draft', 'open'], array_column($rows, 'status'));
		$this->assertSame([true, true], array_column($rows, 'accessible'));
		$this->assertSame(['Linked 4', 'Linked 5'], array_column($rows, 'title'));
	}//end testALinkedFormMissingUpstreamFallsBackToTheLinkStatus()

	public function testAnExpiredFormIsClosed(): void {
		$this->forms[1] = ['id' => 1, 'hash' => 'h', 'title' => 'T', 'description' => 'D', 'expires' => self::FAR_PAST, 'state' => 0];

		$rows = $this->provider(true, [$this->link(1)])->list('r', 's', 'obj-1');

		$this->assertSame('closed', $rows[0]['status']);
		$this->assertFalse($rows[0]['accessible']);
	}//end testAnExpiredFormIsClosed()

	public function testWithoutLinksTheFormsCarryingTheObjectMarkerAreListed(): void {
		$this->markerRows = [
			['id' => 9, 'title' => 'Marked [or:obj-1]', 'description' => 'D', 'hash' => 'h9', 'expires' => 0, 'state' => 2],
			['id' => 0, 'title' => 'No id'],
		];
		$this->counts[9] = 4;

		$rows = $this->provider(true, [])->list('r', 's', 'obj-1');

		$this->assertSame(['9', '0'], array_column($rows, 'id'));
		$this->assertSame('draft', $rows[0]['status']);
		$this->assertSame(4, $rows[0]['submissionCount']);
		$this->assertSame('/index.php/apps/forms/h9', $rows[0]['url']);
		$this->assertSame(0, $rows[1]['submissionCount']);
		$this->assertSame('/index.php/apps/forms/0', $rows[1]['url']);
		$this->assertSame(['forms_v2_forms:%[or:obj-1]%', 'forms_v2_submissions:9'], $this->queries);
	}//end testWithoutLinksTheFormsCarryingTheObjectMarkerAreListed()

	public function testAFailingLinkTableAndAFailingMarkerScanListNothing(): void {
		$this->markerRows = new RuntimeException('no forms table');

		$this->assertSame([], $this->provider(true, new RuntimeException('no link table'))->list('r', 's', 'obj-1'));
	}//end testAFailingLinkTableAndAFailingMarkerScanListNothing()
}//end class
