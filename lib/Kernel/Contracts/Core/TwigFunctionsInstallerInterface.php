<?php
/**
 * TwigFunctionsInstallerInterface: puts the mapping filters and functions on a Twig environment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use Twig\Environment;

/**
 * The template functions Core's computed fields evaluate with.
 *
 * Core builds the sandboxed environment for computed properties and asks this
 * port to add the filters, functions and their runtime; Integrations owns the
 * mapping extension and binds its installer in its own wiring.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface TwigFunctionsInstallerInterface {

	/**
	 * Add the mapping extension and the runtime loader its functions call.
	 *
	 * @param Environment $twig The environment to extend.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function installOn(Environment $twig): void;
}//end interface
