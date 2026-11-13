<?php

/**
 * Unit tests for FlowTasksProvider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Search\FlowTasksProvider;
use OCA\OpenRegister\Service\Task\TaskInboxPage;
use OCA\OpenRegister\Service\Task\TaskInboxServiceInterface;
use OCP\IDateTimeFormatter;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud search finds the flow tasks a user may see.
 *
 * @covers \OCA\OpenRegister\Search\FlowTasksProvider
 * @uses   \OCA\OpenRegister\Db\TaskInboxAudience
 * @uses   \OCA\OpenRegister\Db\TaskInboxCriteria
 * @uses   \OCA\OpenRegister\Db\TaskInboxFilter
 * @uses   \OCA\OpenRegister\Db\TaskInboxOrder
 * @uses   \OCA\OpenRegister\Db\TaskInboxSelection
 * @uses   \OCA\OpenRegister\Db\TaskInboxAnchor
 * @uses   \OCA\OpenRegister\Db\TaskInboxDueWindow
 * @uses   \OCA\OpenRegister\Service\Task\TaskInboxPage
 *
 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
 */
class FlowTasksProviderTest extends TestCase {

	/**
	 * The inbox reads made, as [criteria, page].
	 *
	 * @var array<int, array{0: TaskInboxCriteria, 1: TaskInboxPage}>
	 */
	private array $reads = [];

	/**
	 * The provider over an inbox that answers the given rows and total.
	 *
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @param int                              $total The total.
	 *
	 * @return FlowTasksProvider
	 */
	private function provider(array $rows, int $total): FlowTasksProvider {
		$inbox = $this->createMock(TaskInboxServiceInterface::class);
		$inbox->method('inbox')->willReturnCallback(function (TaskInboxCriteria $criteria, ?TaskInboxPage $page = null) use ($rows, $total): array {
			$this->reads[] = [$criteria, $page];
			return ['results' => $rows, 'total' => $total, 'limit' => $page->limit, 'offset' => $page->offset];
		});
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['kcc']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $params): string => '/apps/openregister/flow-tasks/' . $params['uuid'] . '#' . $route);
		$dates = $this->createMock(IDateTimeFormatter::class);
		$dates->method('formatDate')->willReturnCallback(static fn (int $timestamp): string => gmdate('j M Y', $timestamp));

		return new FlowTasksProvider($inbox, $groups, $l10n, $urls, $dates);
	}//end provider()

	/**
	 * A search query.
	 *
	 * @param string          $term   The term.
	 * @param int|string|null $cursor The cursor.
	 *
	 * @return ISearchQuery
	 */
	private function query(string $term, int|string|null $cursor = null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getCursor')->willReturn($cursor);
		$query->method('getLimit')->willReturn(25);
		return $query;
	}//end query()

	/**
	 * The searcher.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caseworker');
		return $user;
	}//end user()

	/**
	 * A match is the task's title, its state, due date and subject, and the task page.
	 *
	 * @return void
	 */
	public function testAMatchIsListedWithItsSublineAndPage(): void {
		$row = ['uuid' => 't-1', 'displayTitle' => 'Terugbellen mevrouw Jansen', 'state' => 'active', 'dueAt' => '2026-11-20T09:00:00+00:00', 'overdue' => false, 'subject' => ['title' => 'Melding 2026-0412']];

		$result = $this->provider([$row], 1)->search($this->user(), $this->query(' terugbellen jansen '))->jsonSerialize();

		$this->assertSame('Tasks', $result['name']);
		$this->assertFalse($result['isPaginated']);
		$entry = $result['entries'][0]->jsonSerialize();
		$this->assertSame('Terugbellen mevrouw Jansen', $entry['title']);
		$this->assertSame('In progress · due 20 Nov 2026 · Melding 2026-0412', $entry['subline']);
		$this->assertSame('/apps/openregister/flow-tasks/t-1#openregister.task.open', $entry['resourceUrl']);
		$this->assertSame(['uuid' => 't-1'], $entry['attributes']);
	}//end testAMatchIsListedWithItsSublineAndPage()

	/**
	 * The search is the inbox's `all` for the searcher, never as an administrator, open tasks first.
	 *
	 * @return void
	 */
	public function testTheInboxQueryIsTheAccessRule(): void {
		$this->provider([], 0)->search($this->user(), $this->query('terugbellen'));

		[$criteria, $page] = $this->reads[0];
		$this->assertSame('caseworker', $criteria->uid);
		$this->assertSame(['kcc'], $criteria->groupIds);
		$this->assertFalse($criteria->isAdmin);
		$this->assertSame(TaskInboxCriteria::SCOPE_ALL, $criteria->scope);
		$this->assertSame('terugbellen', $criteria->term);
		$this->assertTrue($criteria->openFirst);
		$this->assertSame([FlowTasksProvider::PAGE_LIMIT, 0], [$page->limit, $page->offset]);
	}//end testTheInboxQueryIsTheAccessRule()

	/**
	 * A full page carries the next offset as its cursor, and the cursor is read back.
	 *
	 * @return void
	 */
	public function testCursorPaging(): void {
		$rows = array_fill(0, 10, ['uuid' => 't', 'title' => 'Bel terug', 'state' => 'enabled']);

		$first = $this->provider($rows, 25)->search($this->user(), $this->query('bel'))->jsonSerialize();
		$this->assertTrue($first['isPaginated']);
		$this->assertSame(10, $first['cursor']);

		$last = $this->provider(array_slice($rows, 0, 5), 25)->search($this->user(), $this->query('bel', '20'))->jsonSerialize();
		$this->assertFalse($last['isPaginated']);
		$this->assertSame(20, $this->reads[1][1]->offset);
	}//end testCursorPaging()

	/**
	 * Overdue wins the state word; a finished task, no date and an unreadable subject say only what is known.
	 *
	 * @return void
	 */
	public function testTheSublineSaysOnlyWhatIsKnown(): void {
		$rows = [
			['uuid' => 'a', 'title' => 'A', 'state' => 'active', 'overdue' => true, 'dueAt' => '2026-11-01T09:00:00+00:00', 'subject' => null],
			['uuid' => 'b', 'title' => 'B', 'state' => 'completed', 'subject' => ['title' => '']],
			['uuid' => 'c', 'title' => 'C', 'state' => 'disabled'],
			['uuid' => 'd', 'title' => 'D', 'state' => 'terminated'],
		];

		$entries = $this->provider($rows, 4)->search($this->user(), $this->query('x'))->jsonSerialize()['entries'];

		$this->assertSame(
			['Overdue · due 1 Nov 2026', 'Done', 'Skipped', 'Stopped'],
			array_map(static fn ($e): string => $e->jsonSerialize()['subline'], $entries)
		);
	}//end testTheSublineSaysOnlyWhatIsKnown()

	/**
	 * A blank term asks the inbox nothing.
	 *
	 * @return void
	 */
	public function testABlankTermFindsNothing(): void {
		$result = $this->provider([['uuid' => 't']], 1)->search($this->user(), $this->query('   '))->jsonSerialize();

		$this->assertSame([], $result['entries']);
		$this->assertSame([], $this->reads);
	}//end testABlankTermFindsNothing()

	/**
	 * The provider's id, order and filters.
	 *
	 * @return void
	 */
	public function testIdentity(): void {
		$provider = $this->provider([], 0);

		$this->assertSame('openregister_flow_tasks', $provider->getId());
		$this->assertSame(11, $provider->getOrder('', []));
		$this->assertSame(['term'], $provider->getSupportedFilters());
		$this->assertSame([], $provider->getAlternateIds());
		$this->assertSame([], $provider->getCustomFilters());
	}//end testIdentity()
}//end class
