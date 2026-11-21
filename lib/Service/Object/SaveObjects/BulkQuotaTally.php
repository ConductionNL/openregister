<?php

/**
 * One organisation's quota tally within a bulk save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

/**
 * The stored count of one register, schema and organisation, plus the creates
 * of this batch already admitted against it; or a count that failed.
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
 */
final class BulkQuotaTally {

	/**
	 * Creates of this batch admitted so far.
	 *
	 * @var int
	 */
	public int $admitted = 0;

	/**
	 * Constructor.
	 *
	 * @param string   $schemaRef  The schema as the rows name it.
	 * @param string   $schemaSlug The schema slug, for the message.
	 * @param int      $limit      The cap.
	 * @param int|null $stored     The stored count; null when it could not be made.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
	 */
	public function __construct(
		public readonly string $schemaRef,
		public readonly string $schemaSlug,
		public readonly int $limit,
		public readonly ?int $stored,
	) {
	}//end __construct()

	/**
	 * Whether one more create fits; admits it when it does.
	 *
	 * @return bool True when the create is admitted.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
	 */
	public function admit(): bool {
		if ($this->stored === null || $this->held() >= $this->limit) {
			return false;
		}

		$this->admitted++;

		return true;
	}//end admit()

	/**
	 * What the organisation holds once this batch's admitted creates land.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
	 */
	public function held(): int {
		return (int)$this->stored + $this->admitted;
	}//end held()
}//end class
