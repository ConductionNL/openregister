<?php

/**
 * Twig extension for authentication token functions.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Registers authentication token functions for use in Twig templates.
 *
 * @package OCA\OpenRegister\Twig
 */
class AuthenticationExtension extends AbstractExtension
{
    /**
     * Get the Twig functions provided by this extension.
     *
     * @return TwigFunction[] Array of TwigFunction instances
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('oauthToken', [AuthenticationRuntime::class, 'oauthToken']),
            new TwigFunction('decosToken', [AuthenticationRuntime::class, 'decosToken']),
            new TwigFunction('jwtToken', [AuthenticationRuntime::class, 'jwtToken']),
        ];

    }//end getFunctions()
}//end class
