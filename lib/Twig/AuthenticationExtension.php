<?php

/**
 * Twig extension for authentication token functions.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
=======
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-28
=======
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
