<?php

/**
 * ProviderCatalogue — read-only loader for the credential-broker provider catalogue.
 *
 * Loads the runtime-immutable catalogue shipped at
 * `lib/Settings/credential-providers.json` (design.md D2). Each entry declares a
 * provider's `identifier`, `title`, `baseUrl` (host-lock), `authScheme`
 * (`{header, template}` with a `{secret}` placeholder) and `allowRules[]`
 * (`{method, pathPattern}` — the constrained-proxy guardrails). The catalogue is a
 * SECURITY control: it is loaded from disk server-side and is never writable through
 * any API, so the allow-rules cannot be widened at runtime — new providers/rules
 * ship only in a reviewed release. The parsed catalogue is cached in-memory for the
 * request.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * In-memory, read-only accessor for the shipped provider catalogue.
 */
class ProviderCatalogue
{
    /**
     * App-relative path to the shipped catalogue file.
     *
     * @var string
     */
    private const CATALOGUE_PATH = '/lib/Settings/credential-providers.json';

    /**
     * Parsed provider map (`identifier => entry`), or null before first load.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $cache = null;

    /**
     * Constructor.
     *
     * @param IAppManager     $appManager Resolves the openregister app path on disk.
     * @param LoggerInterface $logger     Logger for load diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return one catalogue entry by provider identifier, or null when unknown.
     *
     * @param string $providerId The catalogue provider identifier (e.g. `github`).
     *
     * @return array<string, mixed>|null The provider entry, or null.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public function get(string $providerId): ?array
    {
        $providers = $this->all();
        $entry     = ($providers[$providerId] ?? null);
        if (is_array($entry) === true) {
            return $entry;
        }

        return null;
    }//end get()

    /**
     * Return the whole provider map (`identifier => entry`).
     *
     * Values are EXPECTED to be entry arrays, but load() only validates the
     * top-level `providers` map — not each entry — so consumers must guard
     * per-entry shape themselves (the file is read unvalidated from disk).
     *
     * @return array<string, mixed> The parsed provider catalogue.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = $this->load();
        return $this->cache;
    }//end all()

    /**
     * Read and parse the catalogue file from disk (read-only).
     *
     * Fails soft: any read/parse error logs a warning and yields an empty
     * catalogue, so a broker call fails closed (no provider resolves) rather
     * than the whole app erroring. Only the top-level `providers` map is
     * validated; per-entry shape is the consumer's responsibility.
     *
     * @return array<string, mixed> The parsed provider map, or empty on error.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function load(): array
    {
        $path = $this->appManager->getAppPath('openregister').self::CATALOGUE_PATH;

        if (is_file($path) === false || is_readable($path) === false) {
            $this->logger->warning('[ProviderCatalogue] catalogue file missing or unreadable: '.$path);
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->logger->warning('[ProviderCatalogue] catalogue file could not be read: '.$path);
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false || is_array(($decoded['providers'] ?? null)) === false) {
            $this->logger->warning('[ProviderCatalogue] catalogue file is not valid provider JSON: '.$path);
            return [];
        }

        return $decoded['providers'];
    }//end load()
}//end class
