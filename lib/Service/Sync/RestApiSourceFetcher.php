<?php

/**
 * OpenRegister REST API Source Fetcher
 *
 * Concrete fetcher for `rest-api` and `openregister` source types. The
 * Gather stage pages through the collection endpoint (page-number,
 * offset-limit, cursor, or Link-header styles) and extracts an identifier
 * per record; the Fetch stage retrieves the full record by id. Supports
 * none / apikey / basic authentication and RFC 7232 If-Modified-Since for
 * incremental sync. Credentials are decrypted from the source's encrypted
 * authConfig via ICrypto and are never logged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Sync;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Source;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REST/OpenRegister transport for the harvest pipeline.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RestApiSourceFetcher implements SourceFetcherInterface {

	/**
	 * Maximum pages to walk during Gather (runaway guard).
	 */
	private const MAX_PAGES = 1000;

	/**
	 * The identifier field of a listed item.
	 *
	 * The identifier field is always 'id'. It used to be looked up on
	 * $source->getConfiguration(), and Source has no `configuration`
	 * property, column or serialized field, so that call threw
	 * "configuration is not a valid attribute" out of Entity::__call on
	 * every gather. Nothing in the app, the UI or the specs ever set an
	 * identifierField, so the lookup had no store to read. Give sources a
	 * real configuration column first if the field ever needs to be
	 * settable.
	 */
	private const ID_FIELD = 'id';

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient HTTP client
	 * @param ICrypto $crypto Credential decryption
	 * @param LoggerInterface $logger Logger
	 * @param HarvestHttpClient|null $harvestHttp The guarded harvest client every request goes through
	 *                                            (null: the bare client, for callers that build this by hand)
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
		private readonly ?HarvestHttpClient $harvestHttp = null,
	) {
	}//end __construct()

	/**
	 * The type this fetcher declares.
	 *
	 * @return string `rest-api`
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getType(): string {
		return 'rest-api';
	}//end getType()

	/**
	 * The name shown in the source form's type list.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getDisplayName(): string {
		return 'REST API';
	}//end getDisplayName()

	/**
	 * The REST fetcher reads its endpoint and credentials from the source's own fields; it takes no config.
	 *
	 * @return array<string, mixed> An object schema with no properties.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function getConfigSchema(): array {
		return ['type' => 'object', 'properties' => new \stdClass()];
	}//end getConfigSchema()

	/**
	 * One bounded GET: through the guarded harvest client when there is one.
	 *
	 * @param string                $url     The URL.
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return \Psr\Http\Message\ResponseInterface The response.
	 */
	private function send(string $url, array $headers): \Psr\Http\Message\ResponseInterface {
		if ($this->harvestHttp !== null) {
			$response = $this->harvestHttp->get(url: $url, headers: $headers);
			// The guarded client hands back error statuses; fail them as the bare client does.
			if ($response->getStatusCode() >= 400) {
				throw new RuntimeException(sprintf('HTTP %d from %s', $response->getStatusCode(), $url));
			}

			return $response;
		}

		// Bound each request so a hung upstream cannot stall the harvest job
		// indefinitely (harden-harvest-http). Guzzle defaults to no timeout.
		return $this->httpClient->request(
			'GET',
			$url,
			['headers' => $headers, 'timeout' => 30, 'connect_timeout' => 5]
		);
	}//end send()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $type The source type
	 *
	 * @return bool True for rest-api and openregister
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function supports(string $type): bool {
		return in_array($type, ['rest-api', 'openregister'], true);
	}//end supports()

	/**
	 * {@inheritDoc}
	 *
	 * @param Source $source The source
	 * @param string|null $since Incremental checkpoint (ISO-8601 timestamp), or null
	 *
	 * @return list<string> External record identifiers
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function gather(Source $source, ?string $since = null): array {
		$baseUrl = (string)$source->getDatabaseUrl();
		if ($baseUrl === '') {
			throw new RuntimeException('Source has no endpoint URL configured.');
		}

		$run = new ListingGather(headers: $this->buildHeaders(source: $source, since: $since));

		$url = $baseUrl;
		$page = 0;

		while ($url !== null && $page < self::MAX_PAGES) {
			$page++;
			$url = $this->gatherPage(run: $run, url: $url);
		}

		return array_values(array_unique($run->ids));
	}//end gather()

	/**
	 * Request one listing page, collect its ids and return the next page's URL.
	 *
	 * @param ListingGather $run The listing: its headers, and the ids gathered so far, appended to.
	 * @param string        $url The page URL.
	 *
	 * @return string|null The next page URL, or null when this was the last (or 304 / non-JSON).
	 */
	private function gatherPage(ListingGather $run, string $url): ?string {
		// Bound each request so a hung upstream cannot stall the harvest job
		// indefinitely (harden-harvest-http). Guzzle defaults to no timeout.
		$response = $this->send(url: $url, headers: $run->headers);

		// 304 Not Modified: nothing changed since the checkpoint.
		if ($response->getStatusCode() === 304) {
			return null;
		}

		$body = json_decode((string)$response->getBody(), true);
		if (is_array($body) === false) {
			return null;
		}

		$this->collectIds(run: $run, items: $this->extractItems(body: $body));

		return $this->nextPageUrl(body: $body, response: $response);
	}//end gatherPage()

	/**
	 * Append the id of every array item that has one.
	 *
	 * @param ListingGather     $run   The listing whose ids are appended to.
	 * @param array<int, mixed> $items The listed items.
	 *
	 * @return void
	 */
	private function collectIds(ListingGather $run, array $items): void {
		foreach ($items as $item) {
			if (is_array($item) === true && isset($item[self::ID_FIELD]) === true) {
				$run->ids[] = (string)$item[self::ID_FIELD];
			}
		}
	}//end collectIds()

	/**
	 * {@inheritDoc}
	 *
	 * @param Source $source The source
	 * @param string $externalId The external record id
	 *
	 * @return array<string, mixed> The raw record
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function fetch(Source $source, string $externalId): array {
		$baseUrl = rtrim((string)$source->getDatabaseUrl(), '/');
		$headers = $this->buildHeaders(source: $source, since: null);
		$url = $baseUrl . '/' . rawurlencode($externalId);
		// Bound the request (harden-harvest-http): a hung upstream must not stall
		// the harvest job.
		$response = $this->send(url: $url, headers: $headers);
		$body = json_decode((string)$response->getBody(), true);

		if (is_array($body) === false) {
			throw new RuntimeException(sprintf('Non-JSON response fetching record %s.', $externalId));
		}

		return $body;
	}//end fetch()

	/**
	 * Build request headers including auth and conditional-request headers.
	 *
	 * @param Source $source The source
	 * @param string|null $since ISO-8601 checkpoint for If-Modified-Since, or null
	 *
	 * @return array<string, string> The headers
	 */
	private function buildHeaders(Source $source, ?string $since): array {
		// Replace, not merge: a numeric header name keeps its key.
		return array_replace(
			['Accept' => 'application/json'],
			$this->authHeaders(source: $source),
			$this->sinceHeaders(since: $since)
		);
	}//end buildHeaders()

	/**
	 * The auth header for the source's auth type, from its decrypted auth config.
	 *
	 * @param Source $source The source.
	 *
	 * @return array<string, string> Empty for none / unsupported-here.
	 */
	private function authHeaders(Source $source): array {
		$auth = $this->decryptAuthConfig(source: $source);

		return match ((string)$source->getAuthType()) {
			'apikey' => [(string)($auth['header'] ?? 'X-Api-Key') => (string)($auth['key'] ?? '')],
			'basic' => [
				'Authorization' => 'Basic ' . base64_encode(
					(string)($auth['username'] ?? '') . ':' . (string)($auth['password'] ?? '')
				),
			],
			default => [],
		};
	}//end authHeaders()

	/**
	 * The If-Modified-Since header for a parseable checkpoint, else none.
	 *
	 * @param string|null $since The checkpoint, or null.
	 *
	 * @return array<string, string>
	 */
	private function sinceHeaders(?string $since): array {
		if ($since === null) {
			return [];
		}

		$ts = strtotime($since);
		if ($ts === false) {
			return [];
		}

		return ['If-Modified-Since' => gmdate('D, d M Y H:i:s', $ts) . ' GMT'];
	}//end sinceHeaders()

	/**
	 * Decrypt the source's auth config blob.
	 *
	 * @param Source $source The source
	 *
	 * @return array<string, mixed> The decrypted credentials (empty on failure)
	 */
	private function decryptAuthConfig(Source $source): array {
		$config = $source->getAuthConfig();
		if (is_array($config) === false || $config === []) {
			return [];
		}

		$decrypted = [];
		foreach ($config as $key => $value) {
			$decrypted[$key] = $this->decryptValue(value: $value);
		}

		return $decrypted;
	}//end decryptAuthConfig()

	/**
	 * Decrypt one auth-config value; non-strings, '' and undecryptable values are used as-is.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return mixed
	 */
	private function decryptValue($value) {
		if (is_string($value) === false || $value === '') {
			return $value;
		}

		try {
			return $this->crypto->decrypt($value);
		} catch (\Throwable $e) {
			// Value was not encrypted (or key rotated): use as-is.
			return $value;
		}
	}//end decryptValue()

	/**
	 * Extract the list of items from a collection response body.
	 *
	 * Supports common envelopes: results[], items[], data[], or a bare array.
	 *
	 * @param array<string, mixed>|list<mixed> $body The decoded body
	 *
	 * @return list<mixed> The items
	 */
	private function extractItems(array $body): array {
		foreach (['results', 'items', 'data', 'value'] as $key) {
			if (isset($body[$key]) === true && is_array($body[$key]) === true) {
				return array_values($body[$key]);
			}
		}

		// Bare list of records.
		if (array_is_list($body) === true) {
			return $body;
		}

		return [];
	}//end extractItems()

	/**
	 * Resolve the next-page URL from the body or Link header.
	 *
	 * @param array<string, mixed> $body The decoded body
	 * @param mixed $response The PSR-7 response
	 *
	 * @return string|null The next URL or null when exhausted
	 */
	private function nextPageUrl(array $body, $response): ?string {
		$next = $this->bodyNextUrl(body: $body);
		if ($next !== null) {
			return $next;
		}

		return $this->linkHeaderNextUrl(response: $response);
	}//end nextPageUrl()

	/**
	 * The next-page URL in the body: `_links.next.href`, else a non-empty top-level `next`.
	 *
	 * @param array $body The decoded page body.
	 *
	 * @return string|null
	 */
	private function bodyNextUrl(array $body): ?string {
		if (isset($body['_links']['next']['href']) === true) {
			return (string)$body['_links']['next']['href'];
		}

		$next = ($body['next'] ?? null);
		if (is_string($next) === true && $next !== '') {
			return $next;
		}

		return null;
	}//end bodyNextUrl()

	/**
	 * The `rel="next"` URL of the response's Link header, or null.
	 *
	 * @param mixed $response The HTTP response.
	 *
	 * @return string|null
	 */
	private function linkHeaderNextUrl($response): ?string {
		// Link: <url>; rel="next".
		$link = $response->getHeaderLine('Link');
		if ($link !== '' && preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m) === 1) {
			return $m[1];
		}

		return null;
	}//end linkHeaderNextUrl()
}//end class
