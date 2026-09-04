<?php

/**
 * OpenRegister AppHost — GitHub discovery source.
 *
 * Searches the GitHub repository API by topic, which is how buildiq and hermiq
 * already find their store items. Declaring `"source": "github"` with a
 * `topics` list is what lets them migrate onto the generic plane without
 * writing a controller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store\Source;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Discovery against the GitHub repository search API.
 *
 * 🔴 THE HOST IS A COMPILE-TIME CONSTANT AND MUST STAY ONE.
 *
 * The `openregister` source reads an admin-configured URL, which is exactly
 * why that path carries an SSRF guard and refuses redirects. Here there is no
 * URL for an app or an admin to influence, so there is nothing to guard — a
 * stronger position, and one that only holds while the constant is a constant.
 * Making the host configurable would reintroduce the whole SSRF surface for
 * the sake of a case nobody has asked for.
 *
 * @spec openspec/changes/store-plane-declarative-sources/specs/apphost-store-plane/spec.md#requirement-a-github-source-must-discover-by-topic-against-a-compile-time-host
 */
class GitHubStoreSource implements StoreSourceInterface {
	/**
	 * The only host this source will ever contact.
	 */
	private const API_HOST = 'https://api.github.com';

	/**
	 * Connect + request timeout, seconds.
	 */
	private const TIMEOUT = 10;

	/**
	 * Cards returned by a single search, across every topic.
	 */
	private const MAX_HITS = 30;

	/**
	 * Seconds a search answer stays fresh.
	 *
	 * 🔴 THIS IS NOT AN OPTIMISATION. GitHub's unauthenticated search limit is
	 * ten requests a minute for the whole instance, so without a cache one
	 * person typing past the debounce exhausts it and the store turns
	 * `rate_limited` for everybody. buildiq and hermiq both already cache for
	 * this reason.
	 */
	private const SEARCH_TTL = 60;

	/**
	 * Cache for search answers, or null when no distributed cache is available.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService Nextcloud HTTP client factory.
	 * @param ICacheFactory   $cacheFactory  Distributed cache factory.
	 * @param LoggerInterface $logger        PSR logger, server-side only.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		if ($cacheFactory->isAvailable() === true) {
			$this->cache = $cacheFactory->createDistributed('openregister_store_github');
		}
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function sourceId(): string {
		return 'github';
	}//end sourceId()

	/**
	 * Search every declared topic and merge the results.
	 *
	 * @param StoreManifest $manifest The declaring app's store block.
	 * @param string        $query    Free-text search, possibly empty.
	 * @param string|null   $kind     Kind filter, or null for every kind.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 */
	public function search(StoreManifest $manifest, string $query, ?string $kind): array {
		$topics = $this->topicsFor(manifest: $manifest, kind: $kind);
		if ($topics === []) {
			// A kind that maps to no topic found nothing. That is an empty
			// answer, not a broken one.
			return ['outcome' => GenericStoreService::OUTCOME_OK, 'cards' => []];
		}

		$key = $this->cacheKey(appId: $manifest->appId, topics: $topics, query: $query);
		$hit = ($this->cache?->get($key) ?? null);
		if (is_string($hit) === true) {
			$decoded = json_decode($hit, true);
			if (is_array($decoded) === true && isset($decoded['outcome'], $decoded['cards']) === true) {
				return ['outcome' => (string)$decoded['outcome'], 'cards' => (array)$decoded['cards']];
			}
		}

		$answer = $this->fetchTopics(topics: $topics, query: $query);

		// 🔴 CACHE THE FAILURES TOO. A rate-limited answer that is not cached
		// sends the very next keystroke back at an API that just said stop,
		// which is how a brief limit becomes a persistent one.
		$this->cache?->set($key, json_encode($answer), self::SEARCH_TTL);

		return $answer;
	}//end search()

	/**
	 * Which topics to search for a given kind filter.
	 *
	 * A store may declare one topic per kind, positionally, or a single topic
	 * for everything. An out-of-range kind narrows to nothing rather than
	 * quietly searching every topic, because "no results for that kind" is
	 * true and "here is everything" is not.
	 *
	 * @param StoreManifest $manifest The store block.
	 * @param string|null   $kind     Kind filter, or null.
	 *
	 * @return array<int, string>
	 */
	private function topicsFor(StoreManifest $manifest, ?string $kind): array {
		if ($kind === null || $kind === '' || $manifest->kinds === []) {
			return $manifest->topics;
		}

		$index = array_search(needle: $kind, haystack: $manifest->kinds, strict: true);
		if ($index === false) {
			return [];
		}

		return [($manifest->topics[$index] ?? $manifest->topics[0] ?? '')];
	}//end topicsFor()

	/**
	 * Issue one search per topic and merge, de-duplicating by owner/repo.
	 *
	 * @param array<int, string> $topics Topics to search.
	 * @param string             $query  Free-text search.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 */
	private function fetchTopics(array $topics, string $query): array {
		$client = $this->clientService->newClient();
		$cards = [];
		$outcome = GenericStoreService::OUTCOME_OK;

		foreach ($topics as $topic) {
			if ($topic === '') {
				continue;
			}

			$one = $this->fetchTopic(client: $client, topic: $topic, query: $query);
			if ($one['outcome'] !== GenericStoreService::OUTCOME_OK) {
				// Worst outcome wins across topics: one reachable topic does
				// not make a half-answer look complete.
				$outcome = $one['outcome'];
				continue;
			}

			foreach ($one['cards'] as $card) {
				// De-duplicate by full name: a repository carrying two of the
				// declared topics is one item, not two.
				$cards[$card['slug']] = $card;
			}
		}

		return [
			'outcome' => $outcome,
			'cards' => array_slice(array_values($cards), 0, self::MAX_HITS),
		];
	}//end fetchTopics()

	/**
	 * Search a single topic.
	 *
	 * @param IClient $client The HTTP client.
	 * @param string  $topic  The topic to search.
	 * @param string  $query  Free-text search.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 */
	private function fetchTopic(IClient $client, string $topic, string $query): array {
		$search = 'topic:' . $topic;
		if ($query !== '') {
			$search .= ' ' . $query;
		}

		try {
			$response = $client->get(
				self::API_HOST . '/search/repositories',
				[
					'query' => ['q' => $search, 'per_page' => self::MAX_HITS],
					'headers' => ['Accept' => 'application/vnd.github+json'],
					'timeout' => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					'allow_redirects' => false,
					'http_errors' => false,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[store/github] search failed: ' . $e->getMessage(),
				['topic' => $topic]
			);
			return ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []];
		}

		$outcome = $this->outcomeFor(status: $response->getStatusCode());
		if ($outcome !== GenericStoreService::OUTCOME_OK) {
			return ['outcome' => $outcome, 'cards' => []];
		}

		$body = json_decode((string)$response->getBody(), true);
		if (is_array($body) === false || is_array(($body['items'] ?? null)) === false) {
			return ['outcome' => GenericStoreService::OUTCOME_INVALID, 'cards' => []];
		}

		$cards = [];
		foreach ($body['items'] as $item) {
			if (is_array($item) === true) {
				$cards[] = $this->toCard(repo: $item, topic: $topic);
			}
		}

		return ['outcome' => GenericStoreService::OUTCOME_OK, 'cards' => $cards];
	}//end fetchTopic()

	/**
	 * Map an HTTP status onto a store outcome.
	 *
	 * 🔴 403 and 429 are RATE LIMITING, not unreachability. GitHub answers 403
	 * for an exhausted limit, which is the case a reader can actually do
	 * something about.
	 *
	 * @param int $status The HTTP status code.
	 *
	 * @return string
	 */
	private function outcomeFor(int $status): string {
		if ($status === 403 || $status === 429) {
			return GenericStoreService::OUTCOME_RATE_LIMITED;
		}

		if ($status < 200 || $status >= 300) {
			return GenericStoreService::OUTCOME_UNREACHABLE;
		}

		return GenericStoreService::OUTCOME_OK;
	}//end outcomeFor()

	/**
	 * Normalise one repository into a store card.
	 *
	 * The slug is `owner/repo`, not the bare name: repository names are not
	 * unique across owners, and a card list keyed on the bare name silently
	 * collapses two different apps into one.
	 *
	 * @param array<string, mixed> $repo One GitHub search result.
	 * @param string               $topic The topic it was found under.
	 *
	 * @return array<string, mixed>
	 */
	private function toCard(array $repo, string $topic): array {
		return [
			'slug' => (string)($repo['full_name'] ?? ''),
			'title' => (string)($repo['name'] ?? ''),
			'description' => (string)($repo['description'] ?? ''),
			'kind' => $topic,
			'version' => (string)($repo['default_branch'] ?? ''),
			'publisher' => (string)(($repo['owner'] ?? [])['login'] ?? ''),
			'stars' => (int)($repo['stargazers_count'] ?? 0),
			'url' => (string)($repo['html_url'] ?? ''),
		];
	}//end toCard()

	/**
	 * Cache key for one search.
	 *
	 * Scoped by app id: two apps searching the same topic still get their own
	 * entry, so a change to one app's block cannot serve stale cards to the
	 * other.
	 *
	 * @param string             $appId  Declaring app.
	 * @param array<int, string> $topics Topics searched.
	 * @param string             $query  Free-text search.
	 *
	 * @return string
	 */
	private function cacheKey(string $appId, array $topics, string $query): string {
		return $appId . ':' . md5(implode(',', $topics) . '|' . $query);
	}//end cacheKey()
}
