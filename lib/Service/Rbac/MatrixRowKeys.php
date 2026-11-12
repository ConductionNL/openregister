<?php

/**
 * MatrixRowKeys: the matrix-row keys read outside the Rbac service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * Keys a matrix row may carry. MatrixRowWhere implements this interface, so
 * MatrixRowWhere::CROSS_ORGANISATION_KEY keeps its value; the repair step that
 * marks implicit cross-organisation read rules reads it here.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
interface MatrixRowKeys {

	/**
	 * The row key that declares a row may read across organisations.
	 *
	 * @var string
	 */
	public const CROSS_ORGANISATION_KEY = 'crossOrganisation';
}//end interface
