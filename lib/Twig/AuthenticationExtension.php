<?php

/**
 * Twig extension for authentication token functions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
     * @spec openspec/specs/object-lifecycle/spec.md
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
