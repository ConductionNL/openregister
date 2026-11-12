<?php

/**
 * DestructionActionInterface: the audit action that records a destruction.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

/**
 * The audit action that records a destruction.
 *
 * Callers outside lib/Service read the constant here; DestructionScope
 * implements this, so DestructionScope::DESTRUCTION_ACTION is the same value.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
interface DestructionActionInterface {
	/**
	 * The audit action that records a destruction.
	 *
	 * A destruction record with no object is the point, so this row is never
	 * inside a scope. Every handler that touches audit rows excludes it from
	 * this one definition.
	 *
	 * @var string
	 */
	public const DESTRUCTION_ACTION = 'object.destroyed';
}//end interface
