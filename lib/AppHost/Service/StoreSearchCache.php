<?php

/**
 * OpenRegister AppHost store search cache
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Keeps a remote registry's search answer for a minute.
 *
 * Apps list store cards on paths every page load reads (a setup wizard's
 * status, for one). Uncached, each of those loads waited on the registry, up
 * to the fetch timeout when it is slow or down. Failures are kept too, as
 * GitHubStoreSource does, so a down registry is not asked again on the very
 * next page.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
final class StoreSearchCache {

	/**
	 * How long a search answer is kept, in seconds.
	 */
	public const TTL = 60;

	/**
	 * The cache, once asked for; null without one.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Whether the cache factory was already asked.
	 *
	 * @var bool
	 */
	private bool $resolved = false;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory|null $cacheFactory Distributed cache factory; null keeps nothing.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function __construct(
		private readonly ?ICacheFactory $cacheFactory=null,
	) {
	}//end __construct()

	/**
	 * The kept answer for these parts, or the fetched one, which is then kept.
	 *
	 * @param string                    $appId The calling app; every key is scoped by it.
	 * @param array<int, string>        $parts What else makes the answer: registry, register, schema, query.
	 * @param callable(): array{outcome: string, cards: array<int, array<string, mixed>>} $fetch Fetches the answer.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function remember(string $appId, array $parts, callable $fetch): array {
		$cache = $this->cache();
		$key   = $appId.':'.hash(algo: 'xxh128', data: implode('|', $parts));

		$kept = $cache?->get($key);
		if (is_string($kept) === true) {
			$decoded = json_decode($kept, true);
			if (is_array($decoded) === true && isset($decoded['outcome'], $decoded['cards']) === true) {
				return ['outcome' => (string)$decoded['outcome'], 'cards' => (array)$decoded['cards']];
			}
		}

		$answer = $fetch();
		$cache?->set($key, (string)json_encode($answer), self::TTL);

		return $answer;
	}//end remember()

	/**
	 * The cache, made on first use; null without one.
	 *
	 * @return ICache|null
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	private function cache(): ?ICache {
		if ($this->resolved === false && $this->cacheFactory !== null && $this->cacheFactory->isAvailable() === true) {
			$this->cache = $this->cacheFactory->createDistributed('openregister_store_search');
		}

		$this->resolved = true;
		return $this->cache;
	}//end cache()
}//end class
