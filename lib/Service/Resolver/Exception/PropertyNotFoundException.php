<?php

/**
 * OpenRegister Resolver PropertyNotFoundException
 *
 * Raised by RegisterResolverService when a property config key resolves
 * to an identifier that does not match any property on the resolved
 * schema. Lives in `Service\Resolver\Exception` for consistency with the
 * Register / Schema not-found exceptions.
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
 * Raised when a resolved property slug/UUID has no matching property on
 * the target schema.
 *
 * @phpstan-consistent-constructor
 */
class PropertyNotFoundException extends Exception
{

    /**
     * Consumer app id (e.g. `openconnector`).
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
     * Slug/UUID/name that failed to resolve to a property.
     *
     * @var string
     */
    private string $resolvedValue;

    /**
     * Slug/UUID of the schema the property lookup targeted.
     *
     * @var string
     */
    private string $schemaIdentifier;


    /**
     * Construct the not-found exception with diagnostic context.
     *
     * @param string         $appId            Consumer app id.
     * @param string         $configKey        Config key that resolved.
     * @param string         $resolvedValue    Slug/UUID/name the lookup attempted.
     * @param string         $schemaIdentifier Schema slug/UUID the lookup targeted.
     * @param Exception|null $previous         Previous exception in the chain.
     */
    public function __construct(
        string $appId,
        string $configKey,
        string $resolvedValue,
        string $schemaIdentifier,
        ?Exception $previous=null,
    ) {
        $this->appId            = $appId;
        $this->configKey        = $configKey;
        $this->resolvedValue    = $resolvedValue;
        $this->schemaIdentifier = $schemaIdentifier;

        parent::__construct(
            message: sprintf(
                'Property "%s" (from config key "%s" on app "%s") not found on schema "%s".',
                $resolvedValue,
                $configKey,
                $appId,
                $schemaIdentifier
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
     * @return string The slug/UUID/name the lookup attempted.
     */
    public function getResolvedValue(): string
    {
        return $this->resolvedValue;

    }//end getResolvedValue()


    /**
     * Get the schema identifier the property lookup targeted.
     *
     * @return string The schema slug or UUID.
     */
    public function getSchemaIdentifier(): string
    {
        return $this->schemaIdentifier;

    }//end getSchemaIdentifier()


}//end class
