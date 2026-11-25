<?php

/**
 * The caller's active organisation, for components outside Core.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Answers which organisation the signed-in user is acting in, as a UUID.
 *
 * A component that keeps organisation-scoped records (export keys, for one)
 * asks this instead of naming Core's organisation service (ADR-013 Rule 1).
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */
interface ActiveOrganisationReaderInterface {

	/**
	 * The active organisation's UUID.
	 *
	 * @return string|null The UUID, or null when there is no user or no active organisation.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function activeOrganisationUuid(): ?string;
}//end interface
