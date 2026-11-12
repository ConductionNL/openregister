<?php

/**
 * Something an audit row names: its stable identifier and the name it carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * An identifier and the human-readable name it carries.
 *
 * The token, its owner and its consumer are each named this way on a
 * {@see TokenIdentity}: the id answers "which one exactly", the name answers
 * "what do people call it". Either may be unknown. For a token the id is its
 * reference, never its value.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class NamedIdentifier {
	/**
	 * Hold the pair.
	 *
	 * @param string|null $id   The stable identifier.
	 * @param string|null $name The name it carries.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		public readonly ?string $id = null,
		public readonly ?string $name = null,
	) {
	}//end __construct()
}//end class
