<?php

/**
 * OpenRegister Resolver SchemaNotFoundException
 *
 * Raised by RegisterResolverService when a config key resolves to a
 * schema slug/UUID that doesn't exist in the caller's tenant. Lives in
 * `Service\Resolver\Exception` to avoid name collision with the generic
 * `OCA\OpenRegister\Exception\SchemaNotFoundException`.
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
 * Raised when a resolved schema slug/UUID has no matching entity.
 *
 * @phpstan-consistent-constructor
 */
class SchemaNotFoundException extends Exception
{

    /**
     * Consumer app id (e.g. `opencatalogi`).
     *
     * @var string
     */
    private string $appId;

    /**
     * Config key the resolver read.
     *
     * @var string
     */
    private string $configKey;

    /**
     * Slug or UUID that failed to resolve to an entity.
     *
     * @var string
     */
    private string $resolvedValue;

    /**
     * Construct the not-found exception with diagnostic context.
     *
     * @param string         $appId         Consumer app id.
     * @param string         $configKey     Config key that resolved.
     * @param string         $resolvedValue Slug/UUID the lookup attempted.
     * @param Exception|null $previous      Previous exception in the chain.
     */
    public function __construct(string $appId, string $configKey, string $resolvedValue, ?Exception $previous=null)
    {
        $this->appId         = $appId;
        $this->configKey     = $configKey;
        $this->resolvedValue = $resolvedValue;

        parent::__construct(
            message: sprintf(
                'Schema "%s" (from config key "%s" on app "%s") not found in caller tenant.',
                $resolvedValue,
                $configKey,
                $appId
            ),
            code: 404,
            previous: $previous
        );

    }//end __construct()

    /**
     * Get the app id the resolver was working against.
     *
     * @return string The consumer app id.
     */
    public function getAppId(): string
    {
        return $this->appId;

    }//end getAppId()

    /**
     * Get the config key that was read.
     *
     * @return string The config key.
     */
    public function getConfigKey(): string
    {
        return $this->configKey;

    }//end getConfigKey()

    /**
     * Get the resolved value that could not be hydrated.
     *
     * @return string The slug or UUID the lookup attempted.
     */
    public function getResolvedValue(): string
    {
        return $this->resolvedValue;

    }//end getResolvedValue()
}//end class
