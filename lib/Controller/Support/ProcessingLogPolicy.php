<?php

/**
 * The configured policy of the processing-log inquiry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use DateTime;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;

/**
 * The privacy-officer (FG) delegation group and the maximum extract range.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class ProcessingLogPolicy {

	/**
	 * App identifier for IAppConfig reads.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * App-config key naming the privacy-officer (FG) delegation group.
	 *
	 * @var string
	 */
	private const FG_GROUP_KEY = 'processing_log_fg_group';

	/**
	 * Default privacy-officer group name.
	 *
	 * @var string
	 */
	private const FG_GROUP_DEFAULT = 'privacy-officer';

	/**
	 * Maximum per-subject extract range in days (configurable).
	 *
	 * @var string
	 */
	private const MAX_RANGE_KEY = 'processing_export_max_range_days';

	/**
	 * Wrap the app config.
	 *
	 * @param IAppConfig $appConfig App-config reader.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Configured privacy-officer (FG) group name.
	 *
	 * @return string The group, the default when configured empty.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function fgGroup(): string {
		$value = $this->appConfig->getValueString(self::APP_ID, self::FG_GROUP_KEY, self::FG_GROUP_DEFAULT);
		if ($value === '') {
			return self::FG_GROUP_DEFAULT;
		}

		return $value;
	}//end fgGroup()

	/**
	 * Validate the requested period against the configured maximum range.
	 *
	 * @param DateTime|null $from Lower bound.
	 * @param DateTime|null $to   Upper bound.
	 *
	 * @return JSONResponse|null 422 when the range is too wide, else null.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function rangeRefusal(DateTime|null $from, DateTime|null $to): JSONResponse|null {
		if ($from === null || $to === null) {
			return null;
		}

		$maxDays = (int)$this->appConfig->getValueInt(self::APP_ID, self::MAX_RANGE_KEY, 366);
		$spanDays = (int)floor(($to->getTimestamp() - $from->getTimestamp()) / 86400);
		if ($spanDays <= $maxDays) {
			return null;
		}

		return new JSONResponse(
			data: [
				'error' => 'Requested period exceeds the maximum extract range',
				'maxDays' => $maxDays,
			],
			statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end rangeRefusal()
}//end class
