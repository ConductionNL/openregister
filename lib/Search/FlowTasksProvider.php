<?php

/**
 * OpenRegister FlowTasksProvider
 *
 * Nextcloud's search bar finds the flow tasks the user may see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxOrder;
use OCA\OpenRegister\Db\TaskInboxSelection;
use OCA\OpenRegister\Service\Task\TaskInboxPage;
use OCA\OpenRegister\Service\Task\TaskInboxServiceInterface;
use OCP\IDateTimeFormatter;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IFilteringProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * The flow tasks a user would see in their inbox, matched on title and description.
 *
 * 🔑 THE INBOX QUERY IS THE ACCESS RULE (design D-1). The provider asks
 * TaskInboxService with scope `all` and never decides visibility itself, so it
 * cannot show a task the inbox would not. An administrator is searched as
 * anyone else (`isAdmin: false`): their `all` would be the whole instance's
 * work, and the search bar lists what is theirs to do.
 *
 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
 */
class FlowTasksProvider implements IFilteringProvider {

	/**
	 * Results per page.
	 */
	public const PAGE_LIMIT = 10;

	/**
	 * Constructor.
	 *
	 * @param TaskInboxServiceInterface $inbox         The inbox query.
	 * @param IGroupManager             $groupManager  The searcher's groups.
	 * @param IL10N                     $l10n          Translations.
	 * @param IURLGenerator             $urlGenerator  The task page link and icon.
	 * @param IDateTimeFormatter        $dateFormatter The due date in the user's format.
	 */
	public function __construct(
		private readonly TaskInboxServiceInterface $inbox,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly IDateTimeFormatter $dateFormatter,
	) {
	}//end __construct()

	/**
	 * The provider id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 */
	public function getId(): string {
		return 'openregister_flow_tasks';
	}//end getId()

	/**
	 * The section name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 */
	public function getName(): string {
		return $this->l10n->t('Tasks');
	}//end getName()

	/**
	 * Right after the objects.
	 *
	 * @param string               $route           The route.
	 * @param array<string, mixed> $routeParameters Its parameters.
	 *
	 * @return int|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 */
	public function getOrder(string $route, array $routeParameters): ?int {
		unset($route, $routeParameters);
		return 11;
	}//end getOrder()

	/**
	 * Only the term.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 */
	public function getSupportedFilters(): array {
		return ['term'];
	}//end getSupportedFilters()

	/**
	 * None.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 */
	public function getAlternateIds(): array {
		return [];
	}//end getAlternateIds()

	/**
	 * None.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 */
	public function getCustomFilters(): array {
		return [];
	}//end getCustomFilters()

	/**
	 * The matching tasks, open ones first, a page at a time.
	 *
	 * @param IUser        $user  The searcher.
	 * @param ISearchQuery $query The search.
	 *
	 * @return SearchResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchResult::complete/paginated is the Nextcloud search pattern
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		if ($term === '') {
			return SearchResult::complete($this->getName(), []);
		}

		$offset = 0;
		$cursor = $query->getCursor();
		if (is_numeric($cursor) === true) {
			$offset = max(0, (int) $cursor);
		}

		$limit = max(1, min($query->getLimit(), self::PAGE_LIMIT));
		$page  = $this->inbox->inbox(criteria: $this->criteria(user: $user, term: $term), page: new TaskInboxPage(limit: $limit, offset: $offset));

		$entries = array_map(fn (array $row): SearchResultEntry => $this->entry(row: $row), $page['results']);
		if (($offset + count($entries)) < $page['total']) {
			return SearchResult::paginated($this->getName(), $entries, $offset + count($entries));
		}

		return SearchResult::complete($this->getName(), $entries);
	}//end search()

	/**
	 * The inbox read for this searcher and term (design D-1).
	 *
	 * @param IUser  $user The searcher.
	 * @param string $term The term.
	 *
	 * @return TaskInboxCriteria
	 */
	private function criteria(IUser $user, string $term): TaskInboxCriteria {
		return new TaskInboxCriteria(
			audience: new TaskInboxAudience(
				uid: $user->getUID(),
				groupIds: $this->groupManager->getUserGroupIds($user),
				isAdmin: false,
				scope: TaskInboxCriteria::SCOPE_ALL,
			),
			selection: new TaskInboxSelection(
				filter: new TaskInboxFilter(term: $term),
				order: new TaskInboxOrder(openFirst: true),
			),
		);
	}//end criteria()

	/**
	 * One result: the title, `{state} · due {date} · {subject}` and the task page.
	 *
	 * @param array<string, mixed> $row An inbox row.
	 *
	 * @return SearchResultEntry
	 */
	private function entry(array $row): SearchResultEntry {
		$uuid  = (string) ($row['uuid'] ?? '');
		$title = (string) ($row['displayTitle'] ?? ($row['title'] ?? ''));

		$entry = new SearchResultEntry(
			'',
			$title,
			$this->subline(row: $row),
			$this->urlGenerator->linkToRoute('openregister.task.open', ['uuid' => $uuid]),
			'icon-checkmark'
		);
		$entry->addAttribute('uuid', $uuid);

		return $entry;
	}//end entry()

	/**
	 * `{state} · due {date} · {subject title}` (design D-4); overdue says so in the state word.
	 *
	 * @param array<string, mixed> $row An inbox row.
	 *
	 * @return string
	 */
	private function subline(array $row): string {
		$parts = [$this->stateWord(row: $row)];

		$due = strtotime((string) ($row['dueAt'] ?? ''));
		if ($due !== false) {
			$parts[] = $this->l10n->t('due %s', [$this->dateFormatter->formatDate($due)]);
		}

		$subject = $row['subject'] ?? null;
		if (is_array($subject) === true && trim((string) ($subject['title'] ?? '')) !== '') {
			$parts[] = trim((string) $subject['title']);
		}

		return implode(' · ', array_filter($parts));
	}//end subline()

	/**
	 * The state as people read it.
	 *
	 * @param array<string, mixed> $row An inbox row.
	 *
	 * @return string
	 */
	private function stateWord(array $row): string {
		if (($row['overdue'] ?? false) === true) {
			return $this->l10n->t('Overdue');
		}

		return match ((string) ($row['state'] ?? '')) {
			'available', 'enabled' => $this->l10n->t('Open'),
			'active' => $this->l10n->t('In progress'),
			'completed' => $this->l10n->t('Done'),
			'terminated' => $this->l10n->t('Stopped'),
			'disabled' => $this->l10n->t('Skipped'),
			default => (string) ($row['state'] ?? ''),
		};
	}//end stateWord()
}//end class
