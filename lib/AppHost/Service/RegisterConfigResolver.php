<?php

/**
 * OpenRegister AppHost — Register Configuration Resolver
 *
 * The AppHost register/schema configuration-resolution contract (ADR-066),
 * seeded from opencatalogi's `ResolvesRegisterConfiguration` trait — the
 * best-shaped of the fleet's three copies (OC trait, docudesk
 * RegisterDiscoveryService, OR RegisterResolverService callers). One instance
 * per leaf app, parameterised by the calling app id, delegating to
 * OpenRegister's canonical {@see \OCA\OpenRegister\Service\RegisterResolverService}.
 *
 * ## Fail-mode (ADR-049 — fail closed)
 *
 * Replaces the per-controller `IAppConfig::getValueString(..., '')` pattern
 * whose empty-string fallback silently hides a misconfigured register/schema
 * (matches zero objects, looks like "no data"), and the nullable
 * catch-Throwable→null resolver anti-pattern:
 *
 *   - OpenRegister/resolver unavailable → {@see FoundationUnavailableException}.
 *   - Config key empty or unset         → {@see ConfigurationMissingException}.
 *
 * Never null, never an empty string.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve an app's configured `<context>_register` / `<context>_schema`
 * identifiers through OpenRegister, failing closed on empty configuration.
 *
 * @psalm-suppress UnusedClass Consumed by leaf apps via Bootstrap registration.
 *
 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md — Requirement: Register configuration resolution
 */
class RegisterConfigResolver
{
    /**
     * Fully-qualified RegisterResolverService name, kept as a plain string so
     * the class is only autoloaded when actually resolved (bootstrap-safety
     * invariant shared with {@see \OCA\OpenRegister\AppHost\Bootstrap}).
     */
    private const REGISTER_RESOLVER_SERVICE = 'OCA\\OpenRegister\\Service\\RegisterResolverService';

    /**
     * Fully-qualified MissingConfigException name (string for the same
     * autoload-safety reason; only referenced inside a resolved-OR path).
     */
    private const MISSING_CONFIG_EXCEPTION = 'OCA\\OpenRegister\\Service\\Resolver\\Exception\\MissingConfigException';

    /**
     * Constructor.
     *
     * @param string             $appId      The calling (leaf) app id.
     * @param IAppManager        $appManager App manager (OR availability check).
     * @param ContainerInterface $container  DI container (lazy OR resolver resolution).
     * @param LoggerInterface    $logger     PSR logger.
     */
    public function __construct(
        private readonly string $appId,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Resolve the configured register identifier (slug or UUID) for a config key.
     *
     * @param string $configKey The `<context>_register` config key (default fleet key: `register`).
     *
     * @return string The configured register slug/UUID — never empty.
     *
     * @throws FoundationUnavailableException When OpenRegister's resolver is unavailable.
     * @throws ConfigurationMissingException  When the config key is empty or unset.
     *
     * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md — Requirement: Register configuration resolution
     */
    public function resolveRegisterId(string $configKey='register'): string
    {
        $resolver = $this->getResolver();

        return $this->guardEmptyConfig(
            configKey: $configKey,
            resolve: fn (): string => (string) $resolver->resolveRegisterId($this->appId, $configKey)
        );
    }//end resolveRegisterId()

    /**
     * Resolve the configured schema identifier (slug or UUID) for a config key.
     *
     * @param string $configKey The `<context>_schema` config key (e.g. `pet_schema`).
     *
     * @return string The configured schema slug/UUID — never empty.
     *
     * @throws FoundationUnavailableException When OpenRegister's resolver is unavailable.
     * @throws ConfigurationMissingException  When the config key is empty or unset.
     *
     * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md — Requirement: Register configuration resolution
     */
    public function resolveSchemaId(string $configKey): string
    {
        $resolver = $this->getResolver();

        return $this->guardEmptyConfig(
            configKey: $configKey,
            resolve: fn (): string => (string) $resolver->resolveSchemaId($this->appId, $configKey)
        );
    }//end resolveSchemaId()

    /**
     * Resolve a register + schema config-key pair in one call.
     *
     * Drop-in replacement for opencatalogi's
     * `ResolvesRegisterConfiguration::resolveRegisterConfiguration()` shape.
     *
     * @param string $registerKey The `<context>_register` config key.
     * @param string $schemaKey   The `<context>_schema` config key.
     *
     * @return array{register: string, schema: string} Map with the resolved identifiers.
     *
     * @throws FoundationUnavailableException When OpenRegister's resolver is unavailable.
     * @throws ConfigurationMissingException  When either config key is empty or unset.
     *
     * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md — Requirement: Register configuration resolution
     */
    public function resolveConfiguration(string $registerKey, string $schemaKey): array
    {
        return [
            'register' => $this->resolveRegisterId(configKey: $registerKey),
            'schema'   => $this->resolveSchemaId(configKey: $schemaKey),
        ];
    }//end resolveConfiguration()

    /**
     * Resolve OpenRegister's RegisterResolverService — fail closed when absent.
     *
     * No catch-Throwable→null: an unavailable foundation surfaces as a typed
     * exception so callers can never silently degrade to "no register".
     *
     * @return object The resolver (exposing resolveRegisterId/resolveSchemaId).
     *
     * @throws FoundationUnavailableException When OpenRegister or its resolver is unavailable.
     */
    private function getResolver(): object
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            $this->logger->error(sprintf('[AppHost:%s] OpenRegister not available — register config resolution refused (fail-closed)', $this->appId));
            throw new FoundationUnavailableException(appId: $this->appId);
        }

        try {
            return $this->container->get(self::REGISTER_RESOLVER_SERVICE);
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('[AppHost:%s] RegisterResolverService unresolvable — register config resolution refused (fail-closed)', $this->appId),
                ['exception' => $e]
            );
            throw new FoundationUnavailableException(
                appId: $this->appId,
                detail: 'RegisterResolverService could not be resolved from the container.',
                previous: $e
            );
        }
    }//end getResolver()

    /**
     * Run a resolver callback, translating OR's MissingConfigException (and an
     * empty resolved value) into the typed AppHost ConfigurationMissingException.
     *
     * @param string   $configKey The config key being resolved (diagnostics).
     * @param callable $resolve   The delegated resolution callback returning the resolved id string.
     *
     * @return string The resolved identifier — never empty.
     *
     * @throws ConfigurationMissingException When the config value is empty or unset.
     */
    private function guardEmptyConfig(string $configKey, callable $resolve): string
    {
        try {
            $value = $resolve();
        } catch (Throwable $e) {
            $missingConfigClass = self::MISSING_CONFIG_EXCEPTION;
            if ($e instanceof $missingConfigClass) {
                throw new ConfigurationMissingException(appId: $this->appId, configKey: $configKey, previous: $e);
            }

            throw $e;
        }

        if ($value === '') {
            throw new ConfigurationMissingException(appId: $this->appId, configKey: $configKey);
        }

        return $value;
    }//end guardEmptyConfig()
}//end class
