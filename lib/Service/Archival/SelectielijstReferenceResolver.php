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
class SelectielijstReferenceResolver implements SelectielijstReferenceResolverInterface {

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
	 * The cache the resolved rows are kept in, once a lookup needed it.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clients      Fetches the published row.
	 * @param ICacheFactory   $cacheFactory Keeps a resolved row.
	 * @param LoggerInterface $logger       Reports a row that could not be read.
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The distributed cache of resolved rows, created on first use.
	 *
	 * @return ICache The cache.
	 */
	private function cache(): ICache {
		return ($this->cache ??= $this->cacheFactory->createDistributed('openregister-selectielijst-reference'));
	}//end cache()

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
		$cached = $this->cache()->get($key);
		if (is_array($cached) === true) {
			return $cached;
		}

		$resolved = $this->fetchReference(url: $url);
		if ($resolved !== null) {
			$this->cache()->set($key, $resolved, self::TTL);
		}

		return $resolved;
	}//end resolve()

	/**
	 * Fetch the selectielijst row behind a resolvable URL and reduce it to its code and name.
	 *
	 * @param string $url The resolvable URL.
	 *
	 * @return array{url: string, code: string, name: string}|null Null when the row cannot be read or has neither.
	 */
	private function fetchReference(string $url): ?array {
		$row = $this->fetchRow(url: $url);
		if (is_array($row) === false) {
			return null;
		}

		return $this->reduceRow(url: $url, row: $row);
	}//end fetchReference()

	/**
	 * Read and decode the row behind a URL; a failed read is logged and gives null.
	 *
	 * @param string $url The resolvable URL.
	 *
	 * @return mixed The decoded body, or null when it could not be read.
	 */
	private function fetchRow(string $url): mixed {
		try {
			$body = (string) $this->clients->newClient()->get($url, ['timeout' => 5, 'headers' => ['Accept' => 'application/json']])->getBody();
			return json_decode($body, true);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SelectielijstReferenceResolver] The selectielijst row could not be read',
				context: ['url' => $url, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end fetchRow()

	/**
	 * Reduce a row to its code (full number, else number) and name.
	 *
	 * @param string               $url The URL the row was read from.
	 * @param array<string, mixed> $row The decoded row.
	 *
	 * @return array{url: string, code: string, name: string}|null Null when the row has neither a code nor a name.
	 */
	private function reduceRow(string $url, array $row): ?array {
		$code = (string) ($row['volledigNummer'] ?? $row['nummer'] ?? '');
		$name = (string) ($row['naam'] ?? '');
		if ($code === '' && $name === '') {
			return null;
		}

		return ['url' => $url, 'code' => $code, 'name' => $name];
	}//end reduceRow()
}//end class
