<?php

/**
 * OpenRegister Mapping Twig Installer Interface
 *
 * The one way code outside lib/Twig puts the mapping filters, functions and
 * their runtime on a Twig environment.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Twig;

use OCA\OpenRegister\Kernel\Contracts\Core\TwigFunctionsInstallerInterface;

/**
 * Puts the mapping filters, functions and their runtime on a Twig environment.
 *
 * Its installOn() is declared by Core's TwigFunctionsInstallerInterface, the port
 * computed fields use; this interface keeps the name lib/Twig callers know.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 */
interface MappingTwigInstallerInterface extends TwigFunctionsInstallerInterface {
}//end interface
