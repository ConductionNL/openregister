<?php

/**
 * AdministrativeRetention - how long administrative audit rows are kept
 *
 * Administrative rows (schema, register, organisation and settings edits) are
 * kept for their own period, the setting `audit.administrativeRetention`: an
 * ISO 8601 duration, `P10Y` by default. It is applied when the row is written,
 * to `retentionPeriod` and `expires`, so the expiry is part of what the chain
 * seals. Domain rows keep the retention of the record they describe.
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

use DateInterval;
use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Kernel\Contracts\AdministrativeRetentionPeriod;
use OCP\IAppConfig;

/**
 * Reads, validates and applies the administrative retention.
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */
class AdministrativeRetention {

	/**
	 * The setting: an ISO 8601 duration.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = AdministrativeRetentionPeriod::CONFIG_KEY;

	/**
	 * The period when nothing, or nothing usable, is stored.
	 *
	 * @var string
	 */
	public const DEFAULT_PERIOD = AdministrativeRetentionPeriod::DEFAULT_PERIOD;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig|null $appConfig Where the period is stored; without it the default applies.
	 */
	public function __construct(
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * The configured period; the default when none, or an unparsable one, is stored.
	 *
	 * @return string An ISO 8601 duration.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	public function period(): string {
		return (new AdministrativeRetentionPeriod(appConfig: $this->appConfig))->period();
	}//end period()

	/**
	 * Store a period, refusing one that is not an ISO 8601 duration.
	 *
	 * @param string $period The period, e.g. `P10Y`.
	 *
	 * @return string The stored period.
	 *
	 * @throws InvalidArgumentException When the period cannot be parsed.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	public function store(string $period): string {
		return (new AdministrativeRetentionPeriod(appConfig: $this->appConfig))->store(period: $period);
	}//end store()

	/**
	 * Stamp an administrative row with the period and the expiry it gives.
	 *
	 * The expiry counts from the row's creation moment, or now when it has none yet.
	 *
	 * @param AuditTrail $row The row.
	 *
	 * @return AuditTrail The same row, stamped.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	public function apply(AuditTrail $row): AuditTrail {
		$period  = $this->period();
		$created = $row->getCreated();
		$from    = new DateTime();
		if ($created !== null) {
			$from = clone $created;
		}

		$row->setRetentionPeriod($period);
		$row->setExpires($from->add(self::interval(period: $period)));

		return $row;
	}//end apply()

	/**
	 * The interval an ISO 8601 duration names.
	 *
	 * @param string $period The duration.
	 *
	 * @return DateInterval
	 *
	 * @throws InvalidArgumentException When it is empty or cannot be parsed.
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
	 */
	public static function interval(string $period): DateInterval {
		return AdministrativeRetentionPeriod::interval(period: $period);
	}//end interval()
}//end class
