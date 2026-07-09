<?php

/**
 * DbalConnectionFactory — builds cached, read-only Doctrine DBAL connections for
 * a `Source` of type `database` (a virtual register).
 *
 * The non-secret connection parameters (driver, host, port, dbname, user, or a
 * sqlite path) live on the Source `authConfig`; the DB PASSWORD is custodied
 * behind the {@see CredentialStore} abstraction (ADR-004) and referenced only by
 * a `credential` UUID on `authConfig`. This factory resolves the secret through
 * that interface — the store selection (Doriath leaf vs. Nextcloud vault) is made
 * upstream by {@see \OCA\OpenRegister\Service\Credential\CredentialStoreResolver}
 * and injected as the already-resolved `CredentialStore`, so this class depends
 * only on the interface (design D1/D2).
 *
 * Connections are cached per request keyed by source id (introspection + a
 * paginated read may open the same source several times in one request). There is
 * no cross-request pool. The factory FAILS CLOSED: on a missing/undecryptable
 * credential, an unsupported/absent driver, or a DBAL error it throws a
 * {@see DbalConnectionException} and never returns an unauthenticated connection.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds and caches read-only DBAL connections for database-type sources.
 */
class DbalConnectionFactory
{
    /**
     * DBAL drivers OpenRegister supports for virtual registers (v1).
     *
     * @var array<int, string>
     */
    public const SUPPORTED_DRIVERS = ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'];

    /**
     * Per-request connection cache keyed by source id. Not a cross-request pool.
     *
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * Constructor.
     *
     * @param CredentialStore $credentialStore The active credential-store leaf (resolved upstream).
     * @param LoggerInterface $logger          Secret-free diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return a cached read-only DBAL connection for the given database source.
     *
     * Fails closed: any credential-resolution or connection error raises a
     * {@see DbalConnectionException}; an unauthenticated connection is never
     * returned. The password is resolved through the credential custody seam and
     * never logged.
     *
     * @param Source $source The `type: database` source to connect to.
     *
     * @return Connection The open (lazy) DBAL connection.
     *
     * @throws DbalConnectionException When the source is misconfigured, the
     *                                 credential cannot be resolved, or DBAL fails.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DriverManager::getConnection is DBAL's
     *   only public connection entry point.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function getConnection(Source $source): Connection
    {
        $cacheKey = $this->cacheKey(source: $source);
        if (isset($this->connections[$cacheKey]) === true) {
            return $this->connections[$cacheKey];
        }

        $params = $this->buildParams(source: $source);

        try {
            $connection = DriverManager::getConnection($params);
        } catch (Throwable $e) {
            // Do NOT include $params — it carries the resolved password.
            $this->logger->warning(
                '[DbalConnectionFactory] could not build connection for source '.$this->sourceLabel(source: $source).': '.$e->getMessage()
            );
            throw new DbalConnectionException(
                'Unable to open a database connection for the configured source.',
                0,
                $e
            );
        }

        $this->connections[$cacheKey] = $connection;
        return $connection;
    }//end getConnection()

    /**
     * Whether the named DBAL driver's PHP extension is available on this instance.
     *
     * Used by the provider's `isEnabled()` to degrade a bound schema to an empty
     * list (rather than erroring) when e.g. `pdo_pgsql` is not installed.
     *
     * @param string $driver The DBAL driver id (e.g. `pdo_pgsql`).
     *
     * @return bool True when the driver is supported and its PDO extension loaded.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) PDO::getAvailableDrivers is the only
     *   way to probe installed PDO drivers.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function isDriverAvailable(string $driver): bool
    {
        $pdoDrivers = [
            'pdo_mysql'  => 'mysql',
            'pdo_pgsql'  => 'pgsql',
            'pdo_sqlite' => 'sqlite',
        ];

        $pdoDriver = ($pdoDrivers[$driver] ?? null);
        if ($pdoDriver === null || extension_loaded('pdo') === false) {
            return false;
        }

        return in_array($pdoDriver, \PDO::getAvailableDrivers(), true);
    }//end isDriverAvailable()

    /**
     * Assemble the DBAL connection parameter array for a database source,
     * resolving the password through the credential custody seam.
     *
     * @param Source $source The database source.
     *
     * @return array<string, mixed> The DriverManager params (may contain the password).
     *
     * @throws DbalConnectionException On invalid config or an unresolvable credential.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function buildParams(Source $source): array
    {
        $config = ($source->getAuthConfig() ?? []);
        if ($config === []) {
            throw new DbalConnectionException('Database source has no connection configuration.');
        }

        $driver = (string) ($config['driver'] ?? '');
        if (in_array($driver, self::SUPPORTED_DRIVERS, true) === false) {
            throw new DbalConnectionException('Unsupported or missing database driver for the source.');
        }

        if ($this->isDriverAvailable(driver: $driver) === false) {
            throw new DbalConnectionException('The database driver extension is not available on this instance.');
        }

        $params = array_merge(
            ['driver' => $driver],
            $this->driverSpecificParams(driver: $driver, config: $config)
        );

        $params['password'] = $this->resolvePassword(source: $source, config: $config);

        return $params;
    }//end buildParams()

    /**
     * The non-secret, driver-specific connection parameters.
     *
     * SQLite sources connect to a file path; network drivers (mysql/pgsql)
     * require host + dbname and optionally port/user.
     *
     * @param string               $driver The validated DBAL driver id.
     * @param array<string, mixed> $config The source `authConfig` block.
     *
     * @return array<string, mixed> The driver-specific params (never a secret).
     *
     * @throws DbalConnectionException When required connection parts are missing.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function driverSpecificParams(string $driver, array $config): array
    {
        if ($driver === 'pdo_sqlite') {
            $path = (string) ($config['path'] ?? '');
            if ($path === '') {
                throw new DbalConnectionException('SQLite database source requires a file path.');
            }

            return ['path' => $path];
        }

        $params = [
            'host'   => (string) ($config['host'] ?? ''),
            'dbname' => (string) ($config['dbname'] ?? ''),
            'user'   => (string) ($config['user'] ?? ''),
        ];

        if (isset($config['port']) === true && $config['port'] !== null && $config['port'] !== '') {
            $params['port'] = (int) $config['port'];
        }

        if ($params['host'] === '' || $params['dbname'] === '') {
            throw new DbalConnectionException('Database source requires host and dbname.');
        }

        return $params;
    }//end driverSpecificParams()

    /**
     * Resolve the source's DB password from the credential custody seam.
     *
     * When a `credential` UUID is configured it MUST resolve to a secret — an
     * absent secret fails closed (never an unauthenticated connection). When no
     * credential is configured (e.g. a local SQLite file or a socket-trusted
     * user) an empty password is used.
     *
     * @param Source               $source The database source (for diagnostics).
     * @param array<string, mixed> $config The source `authConfig` block.
     *
     * @return string The resolved password, or '' when no credential is configured.
     *
     * @throws DbalConnectionException When a configured credential cannot be resolved.
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    private function resolvePassword(Source $source, array $config): string
    {
        $credentialUuid = (string) ($config['credential'] ?? '');
        if ($credentialUuid === '') {
            return '';
        }

        $scope  = (string) ($config['credentialScope'] ?? 'organisation');
        $secret = $this->credentialStore->get($credentialUuid, $scope);

        if ($secret === null) {
            $this->logger->warning(
                '[DbalConnectionFactory] credential could not be resolved for source '.$this->sourceLabel(source: $source).' — failing closed'
            );
            throw new DbalConnectionException('The database credential could not be resolved from the credential store.');
        }

        return $secret;
    }//end resolvePassword()

    /**
     * Build the per-request cache key for a source.
     *
     * @param Source $source The database source.
     *
     * @return string The cache key.
     *
     * @spec exclude Private cache-key helper; behaviour covered by getConnection() caching.
     */
    private function cacheKey(Source $source): string
    {
        return 'id:'.((string) $source->getId()).':uuid:'.((string) $source->getUuid());
    }//end cacheKey()

    /**
     * A secret-free label identifying a source in log lines.
     *
     * @param Source $source The database source.
     *
     * @return string The label (uuid or id — never a credential).
     *
     * @spec exclude Private log-label helper; no behavioural contract.
     */
    private function sourceLabel(Source $source): string
    {
        $uuid = $source->getUuid();
        if ($uuid !== null && $uuid !== '') {
            return (string) $uuid;
        }

        return '#'.(string) $source->getId();
    }//end sourceLabel()
}//end class
