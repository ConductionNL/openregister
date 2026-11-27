<?php

/**
 * AdministrativeRetentionPeriod - the stored period administrative audit rows are kept
 *
 * The setting `audit.administrativeRetention`: an ISO 8601 duration, `P10Y`
 * by default. Read and written here so the settings surface and the audit
 * trail share one rule without either naming the other's component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

use DateInterval;
use InvalidArgumentException;
use OCP\IAppConfig;
use Throwable;

/**
 * Reads, validates and stores the administrative retention period.
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */
final class AdministrativeRetentionPeriod {

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
			$stored = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, self::DEFAULT_PERIOD));
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
		$this->appConfig?->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, $period);

		return $period;
	}//end store()

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
