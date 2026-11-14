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
use OCP\IAppConfig;
use Throwable;

/**
 * Reads, validates and applies the administrative retention.
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */
class AdministrativeRetention {

	/**
	 * The app the setting lives under.
	 *
	 * @var string
	 */
	private const APP = 'openregister';

	/**
	 * The setting: an ISO 8601 duration.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'audit.administrativeRetention';

	/**
	 * The period when nothing, or nothing usable, is stored.
	 *
	 * @var string
	 */
	public const DEFAULT_PERIOD = 'P10Y';

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
		if ($this->appConfig === null) {
			return self::DEFAULT_PERIOD;
		}

		try {
			$stored = trim($this->appConfig->getValueString(self::APP, self::CONFIG_KEY, self::DEFAULT_PERIOD));
			self::interval(period: $stored);
			return $stored;
		} catch (Throwable) {
			return self::DEFAULT_PERIOD;
		}
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
		$period = trim($period);
		self::interval(period: $period);
		$this->appConfig?->setValueString(self::APP, self::CONFIG_KEY, $period);

		return $period;
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
		if (preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', $period) !== 1) {
			throw new InvalidArgumentException('The administrative audit retention must be an ISO 8601 duration such as P10Y, got "' . $period . '".');
		}

		try {
			return new DateInterval($period);
		} catch (Throwable $e) {
			throw new InvalidArgumentException('The administrative audit retention must be an ISO 8601 duration such as P10Y, got "' . $period . '".', 0, $e);
		}
	}//end interval()
}//end class
