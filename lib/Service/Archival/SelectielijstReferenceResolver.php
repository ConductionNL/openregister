<?php

/**
 * Reads the code and name behind a selectielijst URL.
 *
 * A result type stores its selectielijst class as the URL of the published
 * list (the VNG Selectielijst API). A reader needs "6.1, Ingewilligd", and a
 * browser on a Nextcloud page may not call another host, so the server reads
 * the row once and keeps it. Only https URLs on an allowed selectielijst host
 * are fetched, so the endpoint cannot be pointed at an arbitrary address.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a selectielijst result or process type URL to its code and name.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
class SelectielijstReferenceResolver {

	/**
	 * The hosts a selectielijst URL may point at.
	 *
	 * @var string[]
	 */
	public const ALLOWED_HOSTS = ['selectielijst.openzaak.nl'];

	/**
	 * How long a resolved row is kept, in seconds; the published list changes once a year at most.
	 */
	private const TTL = 86400;

	/**
	 * The cache the resolved rows are kept in.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clients      Fetches the published row.
	 * @param ICacheFactory   $cacheFactory Keeps a resolved row.
	 * @param LoggerInterface $logger       Reports a row that could not be read.
	 */
	public function __construct(
		private readonly IClientService $clients,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('openregister-selectielijst-reference');
	}//end __construct()

	/**
	 * Whether a URL is one this resolver will fetch: https, an allowed host, a result or process type.
	 *
	 * @param string $url The stored URL.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function isResolvable(string $url): bool {
		$parts = parse_url($url);
		if (is_array($parts) === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) === true || isset($parts['port']) === true) {
			return false;
		}

		if (in_array(strtolower((string) ($parts['host'] ?? '')), self::ALLOWED_HOSTS, true) === false) {
			return false;
		}

		return preg_match('#^/api/v1/(resultaten|procestypen)/[0-9a-f-]{36}$#', (string) ($parts['path'] ?? '')) === 1;
	}//end isResolvable()

	/**
	 * The code and name behind a selectielijst URL, read once and then from the cache.
	 *
	 * @param string $url The stored URL.
	 *
	 * @return array{url: string, code: string, name: string}|null Null when the URL is not resolvable or the row cannot be read.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function resolve(string $url): ?array {
		if ($this->isResolvable(url: $url) === false) {
			return null;
		}

		$key    = hash(algo: 'sha256', data: $url);
		$cached = $this->cache->get($key);
		if (is_array($cached) === true) {
			return $cached;
		}

		try {
			$body = (string) $this->clients->newClient()->get($url, ['timeout' => 5, 'headers' => ['Accept' => 'application/json']])->getBody();
			$row  = json_decode($body, true);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SelectielijstReferenceResolver] The selectielijst row could not be read',
				context: ['url' => $url, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($row) === false) {
			return null;
		}

		$code = (string) ($row['volledigNummer'] ?? $row['nummer'] ?? '');
		$name = (string) ($row['naam'] ?? '');
		if ($code === '' && $name === '') {
			return null;
		}

		$resolved = ['url' => $url, 'code' => $code, 'name' => $name];
		$this->cache->set($key, $resolved, self::TTL);

		return $resolved;
	}//end resolve()
}//end class
