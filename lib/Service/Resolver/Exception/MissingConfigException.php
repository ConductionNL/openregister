<?php

/**
 * OpenRegister MissingConfigException
 *
 * Thrown when a register/schema resolver is asked to read a config key
 * that is unset and no default was provided. Distinct from
 * RegisterNotFoundException — here the config itself is missing, not
 * the resolved entity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Service\Resolver\Exception
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resolver\Exception;

use Exception;

/**
 * Raised when a resolver-config key is unset and no default was passed.
 *
 * @phpstan-consistent-constructor
 */
class MissingConfigException extends Exception
{

    /**
     * Consumer app id (e.g. `opencatalogi`).
     *
     * @var string
     */
    private string $appId;

    /**
     * Config key the resolver tried to read (e.g. `theme_register`).
     *
     * @var string
     */
    private string $configKey;

    /**
     * Construct the missing-config exception with diagnostic context.
     *
     * @param string         $appId     Consumer app id.
     * @param string         $configKey Config key that was missing.
     * @param Exception|null $previous  Previous exception in the chain.
     */
    public function __construct(string $appId, string $configKey, ?Exception $previous=null)
    {
        $this->appId     = $appId;
        $this->configKey = $configKey;

        parent::__construct(
            message: sprintf(
                'Resolver config key "%s" is not set for app "%s" and no default was provided.',
                $configKey,
                $appId
            ),
            code: 500,
            previous: $previous
        );

    }//end __construct()

    /**
     * Get the app id that was being resolved against.
     *
     * @return string The consumer app id.
     */
    public function getAppId(): string
    {
        return $this->appId;

    }//end getAppId()

    /**
     * Get the config key that was missing.
     *
     * @return string The config key.
     */
    public function getConfigKey(): string
    {
        return $this->configKey;

    }//end getConfigKey()
}//end class
