<?php
/**
 * ObjectQuotaRefusal: the error code and message of a create refused by a schema's object quota.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Why a create was refused by a schema's per-organisation object quota.
 *
 * One wording for the single-object path (the quota listener, Processes) and the
 * bulk path (Core): a refusal with a held count is one at the limit; without one,
 * the quota could not be checked.
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
 */
final class ObjectQuotaRefusal {

	/**
	 * The error code of a create refused at the limit.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'object-quota-exceeded';

	/**
	 * The error code of a create refused because the quota could not be checked.
	 *
	 * @var string
	 */
	public const ERROR_CODE_UNCHECKED = 'object-quota-unchecked';

	/**
	 * Describe the refusal.
	 *
	 * @param string   $schema The schema, as the refusal names it.
	 * @param int|null $held   The objects the organisation already holds; null when the quota could not be checked.
	 * @param int|null $limit  The cap; null when the quota could not be checked.
	 */
	public function __construct(
		public readonly string $schema,
		public readonly ?int $held = null,
		public readonly ?int $limit = null,
	) {
	}//end __construct()

	/**
	 * The error code of this refusal.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function code(): string {
		if ($this->held === null || $this->limit === null) {
			return self::ERROR_CODE_UNCHECKED;
		}

		return self::ERROR_CODE;
	}//end code()

	/**
	 * The message of this refusal.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function message(): string {
		if ($this->held === null || $this->limit === null) {
			return sprintf(
				'Schema "%s" limits how many objects one organisation holds, and that limit could not be checked just now, so nothing was created. Try again.',
				$this->schema
			);
		}

		return sprintf('This organisation already holds %d of at most %d objects of schema "%s".', $this->held, $this->limit, $this->schema);
	}//end message()
}//end class
