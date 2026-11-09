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

use Twig\Environment;

/**
 * Puts the mapping filters, functions and their runtime on a Twig environment.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 */
interface MappingTwigInstallerInterface {
	/**
	 * Add the mapping extension and the runtime loader its functions call.
	 *
	 * @param Environment $twig The environment to extend.
	 *
	 * @return void
	 */
	public function installOn(Environment $twig): void;
}//end interface
