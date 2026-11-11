<?php

/**
 * Object state request
 *
 * What a caller of an archive or freeze verb asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The object an archive or freeze verb acts on, the address it was named at, and why.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */
final class ObjectStateRequest {

	/**
	 * Constructor.
	 *
	 * @param string      $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason     Why, as the caller put it.
	 * @param string|null $register   The register the url names, if any.
	 * @param string|null $schema     The schema the url names, if any.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly ?string $reason,
		public readonly ?string $register,
		public readonly ?string $schema,
	) {
	}//end __construct()
}//end class
