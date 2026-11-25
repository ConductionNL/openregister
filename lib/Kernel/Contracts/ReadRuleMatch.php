<?php

/**
 * The read rule that granted a read, as a read audit entry records it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The matched rule entry, its digest and the schema version it belonged to.
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */
final class ReadRuleMatch {

	/**
	 * Name the rule.
	 *
	 * @param array<mixed>|string $rule    The rule entry as declared on the schema.
	 * @param string              $digest  SHA-256 of the rule's canonical JSON.
	 * @param string|null         $version The schema version the rule belonged to.
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	public function __construct(
		public readonly array|string $rule,
		public readonly string $digest,
		public readonly ?string $version,
	) {
	}//end __construct()
}//end class
