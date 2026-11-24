<?php

/**
 * ExportRightInterface: the right an export requires.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Configuration;

/**
 * The authorization verbs of exporting.
 *
 * An export requires `export`; while no administrator has narrowed it, the
 * schema's `read` grant stands in. The upgrade that writes the export grant
 * reads both names here.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
interface ExportRightInterface {

	/**
	 * The right an export requires.
	 */
	public const ACTION = 'export';

	/**
	 * The right an export falls back to while no administrator has narrowed it.
	 */
	public const FALLBACK_ACTION = 'read';
}//end interface
