<?php

/**
 * OpenRegister MissingConfigException
 *
 * Exception thrown when a required app config key is not set.
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
 * Exception thrown when a required IAppConfig key is not set and no default was provided.
 *
 * Callers should catch this to detect server misconfiguration (HTTP 500 hint).
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
class MissingConfigException extends OpenRegisterException
{

    /**
     * The app ID whose config is missing.
     *
     * @var string
     */
    private readonly string $appId;

    /**
     * The config key that was not set.
     *
     * @var string
     */
    private readonly string $configKey;

    /**
     * Constructor.
     *
     * @param string         $appId     The app ID whose config is missing.
     * @param string         $configKey The config key that was not set.
     * @param Throwable|null $previous  Previous exception if any.
     *
     * @return void
     */
    public function __construct(string $appId, string $configKey, ?Throwable $previous=null)
    {
        $this->appId     = $appId;
        $this->configKey = $configKey;

        parent::__construct(
            message: "Config key '{$configKey}' is not set for app '{$appId}'.",
            code: 500,
            previous: $previous
        );
    }//end __construct()

    /**
     * Get the app ID whose config is missing.
     *
     * @return string
     */
    public function getAppId(): string
    {
        return $this->appId;
    }//end getAppId()

    /**
     * Get the config key that was not set.
     *
     * @return string
     */
    public function getConfigKey(): string
    {
        return $this->configKey;
    }//end getConfigKey()
}//end class
