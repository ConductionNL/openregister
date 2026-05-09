<?php

/**
 * Twig runtime for authentication token functions.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Twig;

use Adbar\Dot;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\AuthenticationService;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Runtime for fetching OAuth, DeCOS, and JWT tokens in Twig templates.
 *
 * @package OCA\OpenRegister\Twig
 */
class AuthenticationRuntime implements RuntimeExtensionInterface
{
    /**
     * Constructor.
     *
     * @param AuthenticationService $authService The authentication service
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
     */
    public function __construct(
        private readonly AuthenticationService $authService,
    ) {

    }//end __construct()

    /**
     * Fetch an OAuth token for a source.
     *
     * @param Source $source The source to authenticate with
     *
     * @return string The OAuth access token
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the request fails.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
     */
    public function oauthToken(Source $source): string
    {
        $configuration = new Dot($source->getConfiguration(), true);
        $authConfig    = $configuration->get('authentication');

        return $this->authService->fetchOAuthTokens($authConfig);

    }//end oauthToken()

    /**
     * Fetch a DeCOS token for a source.
     *
     * @param Source $source The source to authenticate with
     *
     * @return string The DeCOS access token
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the request fails.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
     */
    public function decosToken(Source $source): string
    {
        $configuration = new Dot($source->getConfiguration(), true);
        $authConfig    = $configuration->get('authentication');

        return $this->authService->fetchDecosToken($authConfig);

    }//end decosToken()

    /**
     * Fetch a JWT token for a source.
     *
     * @param Source $source The source to authenticate with
     *
     * @return string The signed JWT token
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
     */
    public function jwtToken(Source $source): string
    {
        $configuration = new Dot($source->getConfiguration(), true);
        $authConfig    = $configuration->get('authentication');

        return $this->authService->fetchJWTToken($authConfig);

    }//end jwtToken()
}//end class
