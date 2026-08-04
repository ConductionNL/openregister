<?php

/**
 * OpenRegister AppHost — ConfigurationMissingException
 *
 * Thrown when an AppHost consumable is asked to resolve an app's register /
 * schema configuration and the stored configuration value is empty or the
 * app's register JSON is absent. Fail-closed per ADR-049: an empty config
 * value is an explicit, typed error — never a silent empty string that
 * matches zero objects and looks like "no data".
 *
 * Distinct from {@see FoundationUnavailableException}: here OpenRegister IS
 * available, but the consuming app's own configuration is missing. The fix is
 * operator action (run the app's setup / POST /api/settings/load), so the
 * message names the missing key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Exception
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

namespace OCA\OpenRegister\AppHost\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when an app's register/schema configuration is empty or missing.
 *
 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md
 *   — Requirement: Register configuration resolution (Scenario: Empty configuration fails closed)
 */
class ConfigurationMissingException extends RuntimeException
{
    /**
     * Construct the configuration-missing exception with diagnostic context.
     *
     * @param string         $appId     The consuming (leaf) app id.
     * @param string         $configKey The empty/missing config key or file (e.g. `register`, `petstore_register.json`).
     * @param Throwable|null $previous  Previous exception in the chain.
     */
    public function __construct(
        private readonly string $appId,
        private readonly string $configKey,
        ?Throwable $previous=null
    ) {
        parent::__construct(
            message: sprintf(
                '[AppHost:%s] configuration "%s" is not set. Run the app setup or POST /api/settings/load to initialise registers and schemas.',
                $appId,
                $configKey
            ),
            code: 503,
            previous: $previous
        );
    }//end __construct()

    /**
     * Get the consuming app id the failure was raised for.
     *
     * @return string The leaf app id.
     */
    public function getAppId(): string
    {
        return $this->appId;
    }//end getAppId()

    /**
     * Get the config key (or register JSON filename) that was missing.
     *
     * @return string The missing config key.
     */
    public function getConfigKey(): string
    {
        return $this->configKey;
    }//end getConfigKey()
}//end class
