<?php

/**
 * DepartmentMatrixCompilerInterface: what callers outside the component use of DepartmentMatrixCompiler.
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
 * Declares only what callers outside the component use of DepartmentMatrixCompiler; the rest
 * of the class stays its own.
 */
interface DepartmentMatrixCompilerInterface {

	/**
	 * The key the matrix is declared under, inside `authorization`.
	 *
	 * @var string
	 */
	public const KEY = 'matrix';
}//end interface
