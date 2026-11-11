<?php

/**
 * UniqueConstraintListenerInterface: what callers outside the Listener component use of UniqueConstraintListener.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

/**
 * The error code a write refused by a unique constraint carries, so a caller can branch on it.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
interface UniqueConstraintListenerInterface {

	/**
	 * The error code a refused write carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'unique-constraint-breached';
}//end interface
