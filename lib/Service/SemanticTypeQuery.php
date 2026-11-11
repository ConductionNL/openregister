<?php

/**
 * SemanticTypeQuery — one semantic-type resolution request.
 *
 * Carries the semantic-type URI and the optional consuming register id (the
 * tie-break bias) through {@see SemanticTypeResolver}.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Value object: the uri to resolve and the consuming register.
 */
final class SemanticTypeQuery {
	/**
	 * Constructor.
	 *
	 * @param string $uri The canonical semantic-type URI.
	 * @param int|null $consumingRegisterId The consuming schema's register id, or null.
	 */
	public function __construct(
		public readonly string $uri,
		public readonly ?int $consumingRegisterId,
	) {
	}//end __construct()
}//end class
