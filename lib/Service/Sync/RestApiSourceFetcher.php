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
class RestApiSourceFetcher implements SourceFetcherInterface
{

    /**
     * Maximum pages to walk during Gather (runaway guard).
     */
    private const MAX_PAGES = 1000;

    /**
     * Constructor.
     *
     * @param Client          $httpClient HTTP client
     * @param ICrypto         $crypto     Credential decryption
     * @param LoggerInterface $logger     Logger
     */
    public function __construct(
        private readonly Client $httpClient,
        private readonly ICrypto $crypto,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @param string $type The source type
     *
     * @return bool True for rest-api and openregister
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function supports(string $type): bool
    {
        return in_array($type, ['rest-api', 'openregister'], true);
    }//end supports()

    /**
     * {@inheritDoc}
     *
     * @param Source      $source The source
     * @param string|null $since  Incremental checkpoint (ISO-8601 timestamp), or null
     *
     * @return list<string> External record identifiers
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function gather(Source $source, ?string $since=null): array
    {
        $baseUrl = (string) $source->getDatabaseUrl();
        if ($baseUrl === '') {
            throw new RuntimeException('Source has no endpoint URL configured.');
        }

        $headers = $this->buildHeaders(source: $source, since: $since);
        $idField = $this->identifierField(source: $source);

        $ids  = [];
        $url  = $baseUrl;
        $page = 0;

        while ($url !== null && $page < self::MAX_PAGES) {
            $page++;
            $response = $this->httpClient->request('GET', $url, ['headers' => $headers]);

            // 304 Not Modified: nothing changed since the checkpoint.
            if ($response->getStatusCode() === 304) {
                break;
            }

            $body = json_decode((string) $response->getBody(), true);
            if (is_array($body) === false) {
                break;
            }

            $items = $this->extractItems(body: $body);
            foreach ($items as $item) {
                if (is_array($item) === true && isset($item[$idField]) === true) {
                    $ids[] = (string) $item[$idField];
                }
            }

            $url = $this->nextPageUrl(body: $body, response: $response);
        }//end while

        return array_values(array_unique($ids));
    }//end gather()

    /**
     * {@inheritDoc}
     *
     * @param Source $source     The source
     * @param string $externalId The external record id
     *
     * @return array<string, mixed> The raw record
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function fetch(Source $source, string $externalId): array
    {
        $baseUrl  = rtrim((string) $source->getDatabaseUrl(), '/');
        $headers  = $this->buildHeaders(source: $source, since: null);
        $url      = $baseUrl.'/'.rawurlencode($externalId);
        $response = $this->httpClient->request('GET', $url, ['headers' => $headers]);
        $body     = json_decode((string) $response->getBody(), true);

        if (is_array($body) === false) {
            throw new RuntimeException(sprintf('Non-JSON response fetching record %s.', $externalId));
        }

        return $body;
    }//end fetch()

    /**
     * Build request headers including auth and conditional-request headers.
     *
     * @param Source      $source The source
     * @param string|null $since  ISO-8601 checkpoint for If-Modified-Since, or null
     *
     * @return array<string, string> The headers
     */
    private function buildHeaders(Source $source, ?string $since): array
    {
        $headers = ['Accept' => 'application/json'];

        $auth = $this->decryptAuthConfig(source: $source);
        switch ((string) $source->getAuthType()) {
            case 'apikey':
                $headerName           = (string) ($auth['header'] ?? 'X-Api-Key');
                $headers[$headerName] = (string) ($auth['key'] ?? '');
                break;

            case 'basic':
                $headers['Authorization'] = 'Basic '.base64_encode(
                    (string) ($auth['username'] ?? '').':'.(string) ($auth['password'] ?? '')
                );
                break;

            default:
                // none / unsupported-here: no auth header.
                break;
        }

        if ($since !== null) {
            $ts = strtotime($since);
            if ($ts !== false) {
                $headers['If-Modified-Since'] = gmdate('D, d M Y H:i:s', $ts).' GMT';
            }
        }

        return $headers;
    }//end buildHeaders()

    /**
     * Decrypt the source's auth config blob.
     *
     * @param Source $source The source
     *
     * @return array<string, mixed> The decrypted credentials (empty on failure)
     */
    private function decryptAuthConfig(Source $source): array
    {
        $config = $source->getAuthConfig();
        if (is_array($config) === false || $config === []) {
            return [];
        }

        $decrypted = [];
        foreach ($config as $key => $value) {
            if (is_string($value) === false || $value === '') {
                $decrypted[$key] = $value;
                continue;
            }

            try {
                $decrypted[$key] = $this->crypto->decrypt($value);
            } catch (\Throwable $e) {
                // Value was not encrypted (or key rotated): use as-is.
                $decrypted[$key] = $value;
            }
        }

        return $decrypted;
    }//end decryptAuthConfig()

    /**
     * Determine the identifier field name for gathered records.
     *
     * @param Source $source The source
     *
     * @return string The id field (defaults to 'id')
     */
    private function identifierField(Source $source): string
    {
        $config = $source->getConfiguration();
        if (is_array($config) === true && isset($config['identifierField']) === true) {
            return (string) $config['identifierField'];
        }

        return 'id';
    }//end identifierField()

    /**
     * Extract the list of items from a collection response body.
     *
     * Supports common envelopes: results[], items[], data[], or a bare array.
     *
     * @param array<string, mixed>|list<mixed> $body The decoded body
     *
     * @return list<mixed> The items
     */
    private function extractItems(array $body): array
    {
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
     * @param array<string, mixed> $body     The decoded body
     * @param mixed                $response The PSR-7 response
     *
     * @return string|null The next URL or null when exhausted
     */
    private function nextPageUrl(array $body, $response): ?string
    {
        // _links.next.href or next at top level.
        if (isset($body['_links']['next']['href']) === true) {
            return (string) $body['_links']['next']['href'];
        }

        if (isset($body['next']) === true && is_string($body['next']) === true && $body['next'] !== '') {
            return $body['next'];
        }

        // Link: <url>; rel="next".
        $link = $response->getHeaderLine('Link');
        if ($link !== '' && preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m) === 1) {
            return $m[1];
        }

        return null;
    }//end nextPageUrl()
}//end class
