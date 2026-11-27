<?php

/**
 * AuditCategory - which history an audit row belongs to
 *
 * The one audit trail holds two histories: the domain history of the records
 * (object writes, reads, files) and the administrative history of the
 * configuration around them (schemas, registers, organisations, settings).
 * Every row carries one of the two as `category`, derived from its action, so
 * an auditor reads the administrative change log apart from record history
 * and an object reader never sees administrative rows.
 *
 * The category is an index over the action, not evidence of its own: the
 * action is sealed, so the category is deliberately OUTSIDE the canonical JSON
 * the chain hashes, and the backfill of existing rows leaves the chain intact.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * The two categories, and the category of an action.
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */
final class AuditCategory {

	/**
	 * Record history: object writes, reads, files.
	 *
	 * @var string
	 */
	public const DOMAIN = 'domain';

	/**
	 * Configuration history: schema, register, organisation and settings edits.
	 *
	 * @var string
	 */
	public const ADMINISTRATIVE = 'administrative';

	/**
	 * The action prefixes of administrative rows.
	 *
	 * @var list<string>
	 */
	public const ADMINISTRATIVE_PREFIXES = ['schema.', 'register.', 'organisation.', 'settings.'];

	/**
	 * The category of a row with this action.
	 *
	 * @param string|null $action The row's action.
	 *
	 * @return string {@see self::ADMINISTRATIVE} for a schema, register,
	 *                organisation or settings action, else {@see self::DOMAIN}.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	public static function forAction(?string $action): string {
		foreach (self::ADMINISTRATIVE_PREFIXES as $prefix) {
			if ($action !== null && str_starts_with($action, $prefix) === true) {
				return self::ADMINISTRATIVE;
			}
		}

		return self::DOMAIN;
	}//end forAction()

	/**
	 * Whether a value names one of the two categories.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
		 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	public static function isCategory(mixed $value): bool {
		return $value === self::DOMAIN || $value === self::ADMINISTRATIVE;
	}//end isCategory()
}//end class
