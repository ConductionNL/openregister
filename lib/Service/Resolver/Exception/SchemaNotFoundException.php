<?php

/**
 * OpenRegister Resolver SchemaNotFoundException
 *
 * Exception thrown when a configured schema slug/UUID cannot be resolved.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Service\Resolver\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-resolver-service/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resolver\Exception;

use OCA\OpenRegister\Exception\OpenRegisterException;
use Throwable;

/**
 * Thrown when a schema config key is set but the resolved slug/UUID matches no entity.
 *
 * Two distinct failure modes surface via this exception:
 * - The schema simply does not exist anywhere (stale config, HTTP 500 hint).
 * - The schema exists but not in the caller's tenant (HTTP 404 hint).
 *
 * Callers can inspect getResolvedValue() to include the stale slug in error responses.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Service\Resolver\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class SchemaNotFoundException extends OpenRegisterException
{

    /**
     * The app ID that owns the config key.
     *
     * @var string
     */
    private readonly string $appId;

    /**
     * The config key whose value could not be resolved.
     *
     * @var string
     */
    private readonly string $configKey;

    /**
     * The resolved slug/UUID value that was looked up.
     *
     * @var string
     */
    private readonly string $resolvedValue;

    /**
     * Constructor.
     *
     * @param string         $appId         The app ID.
     * @param string         $configKey     The config key.
     * @param string         $resolvedValue The slug/UUID that failed to resolve.
     * @param Throwable|null $previous      Previous exception if any.
     *
     * @return void
     */
    public function __construct(
        string $appId,
        string $configKey,
        string $resolvedValue,
        ?Throwable $previous=null
    ) {
        $this->appId         = $appId;
        $this->configKey     = $configKey;
        $this->resolvedValue = $resolvedValue;

        parent::__construct(
            message: "Schema '{$resolvedValue}' (config key '{$configKey}' in app '{$appId}') could not be found.",
            code: 404,
            previous: $previous
        );
    }//end __construct()

    /**
     * Get the app ID.
     *
     * @return string
     */
    public function getAppId(): string
    {
        return $this->appId;
    }//end getAppId()

    /**
     * Get the config key.
     *
     * @return string
     */
    public function getConfigKey(): string
    {
        return $this->configKey;
    }//end getConfigKey()

    /**
     * Get the resolved slug/UUID that failed to hydrate.
     *
     * @return string
     */
    public function getResolvedValue(): string
    {
        return $this->resolvedValue;
    }//end getResolvedValue()
}//end class
