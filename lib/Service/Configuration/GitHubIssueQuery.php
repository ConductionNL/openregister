<?php

/**
 * One listing of GitHub issues for a repository.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The repository, state, sort, page size, label filter and token of one
 * issue listing, the request each label sends, and the OR-merge of the
 * pages several labels return. Each wither returns a changed copy.
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
 */
final class GitHubIssueQuery {

	/**
	 * Issue state filter (`open`, `closed` or `all`).
	 *
	 * @var string
	 */
	private string $state = 'open';

	/**
	 * Sort key.
	 *
	 * @var string
	 */
	private string $sort = 'reactions-+1';

	/**
	 * Page size.
	 *
	 * @var integer
	 */
	private int $perPage = 30;

	/**
	 * The non-empty label filters.
	 *
	 * @var array<int, string>
	 */
	private array $labels = [];

	/**
	 * The app-level token; '' sends the request anonymously.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * Constructor.
	 *
	 * @param string $owner The repository owner (validated by the caller).
	 * @param string $repo  The repository name (validated by the caller).
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
	) {

	}//end __construct()

	/**
	 * A copy with the given state filter and sort key.
	 *
	 * @param string $state The state filter.
	 * @param string $sort  The sort key.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function filteredBy(string $state, string $sort): self {
		$copy = clone $this;
		$copy->state = $state;
		$copy->sort = $sort;
		return $copy;
	}//end filteredBy()

	/**
	 * A copy with the given page size.
	 *
	 * @param int $perPage The page size (1-100).
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function pagedBy(int $perPage): self {
		$copy = clone $this;
		$copy->perPage = $perPage;
		return $copy;
	}//end pagedBy()

	/**
	 * A copy filtered on the given labels; empty labels are dropped.
	 *
	 * @param array<string>|null $labels The labels; null = no filter.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function withLabels(?array $labels): self {
		$copy = clone $this;
		$copy->labels = [];
		if (is_array($labels) === true) {
			$copy->labels = array_values(array_filter($labels, static fn ($l) => $l !== ''));
		}

		return $copy;
	}//end withLabels()

	/**
	 * A copy sending the given token.
	 *
	 * @param string $token The token; '' for anonymous requests.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function withToken(string $token): self {
		$copy = clone $this;
		$copy->token = $token;
		return $copy;
	}//end withToken()

	/**
	 * The non-empty label filters.
	 *
	 * @return array<int, string> The labels.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function labels(): array {
		return $this->labels;
	}//end labels()

	/**
	 * The issues endpoint of the repository under the given API base.
	 *
	 * @param string $apiBase The API base URL.
	 *
	 * @return string The URL.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function url(string $apiBase): string {
		return $apiBase . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/issues';
	}//end url()

	/**
	 * The query and headers of one request, filtered on one label or none.
	 *
	 * @param string|null $label The label, or null for no label filter.
	 *
	 * @return array{query: array<string, mixed>, headers: array<string, string>} The request options.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function requestOptions(?string $label): array {
		return [
			'query' => $this->query(label: $label),
			'headers' => $this->headers(),
		];
	}//end requestOptions()

	/**
	 * The query of one request, filtered on one label or none.
	 *
	 * @param string|null $label The label, or null for no label filter.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function query(?string $label): array {
		$query = [
			'state' => $this->state,
			'sort' => $this->sort,
			'direction' => 'desc',
			'per_page' => $this->perPage,
		];
		if ($label !== null && $label !== '') {
			$query['labels'] = $label;
		}

		return $query;
	}//end query()

	/**
	 * The headers of a request, with the token when there is one.
	 *
	 * @return array<string, string> The headers.
	 */
	private function headers(): array {
		$headers = [
			'Accept' => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		];
		if ($this->token !== '') {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		return $headers;
	}//end headers()

	/**
	 * OR-merge the pages of several labels: first occurrence of each issue
	 * number wins, most reactions first, cut to the page size.
	 *
	 * @param array<int, array<int, array>> $pages The pages, one per label.
	 *
	 * @return array<int, array> The merged issues.
	 *
	 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-1
	 */
	public function merge(array $pages): array {
		$items = array_values($this->firstByNumber(pages: $pages));
		usort(
			$items,
			static function (array $a, array $b): int {
				$aCount = (int)($a['reactions']['total_count'] ?? 0);
				$bCount = (int)($b['reactions']['total_count'] ?? 0);
				return $bCount <=> $aCount;
			}
		);

		return array_slice($items, 0, $this->perPage);
	}//end merge()

	/**
	 * The first occurrence of each positive issue number across the pages, in page order.
	 *
	 * @param array<int, array<int, array>> $pages The pages, one per label.
	 *
	 * @return array<int, array> The issues by number.
	 */
	private function firstByNumber(array $pages): array {
		$merged = [];
		foreach ($pages as $page) {
			foreach ($page as $item) {
				$number = (int)($item['number'] ?? 0);
				if ($number > 0 && isset($merged[$number]) === false) {
					$merged[$number] = $item;
				}
			}
		}

		return $merged;
	}//end firstByNumber()
}//end class
