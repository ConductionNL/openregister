<?php

/**
 * HierarchyGrantExpanderInterface: what callers outside the component use of HierarchyGrantExpander.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Declares only what callers outside the component use of HierarchyGrantExpander; the rest
 * of the class stays its own.
 */
interface HierarchyGrantExpanderInterface {

	/**
	 * The schema annotation that declares the parent edge.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-hierarchy';
}//end interface
