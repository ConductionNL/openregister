<?php

/**
 * OpenRegister AppHost — AppConfig Metric Source
 *
 * Executes `appConfig` metric descriptors: reads an integer IAppConfig value
 * for the calling app and emits it as a single unlabelled sample (counter or
 * gauge). Replaces docudesk/nldesign hand-maintained appconfig counters.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability\Source;

use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\MetricSourceInterface;
use OCP\IAppConfig;

/**
 * AppConfig integer-value source.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.4
 */
class AppConfigMetricSource implements MetricSourceInterface {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config reader.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.4
	 */
	public function kind(): string {
		return 'appConfig';
	}//end kind()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $appId Calling app id.
	 * @param MetricDescriptor $descriptor The metric descriptor.
	 *
	 * @return MetricSample[]
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.4
	 */
	public function collect(string $appId, MetricDescriptor $descriptor): array {
		$key = (string)$descriptor->source['key'];
		$help = $descriptor->help ?? sprintf('App config value for %s', $key);
		$value = $this->appConfig->getValueInt($appId, $key, 0);

		return [
			MetricSample::single(
				name: $descriptor->name,
				type: $descriptor->type,
				help: $help,
				value: $value
			),
		];
	}//end collect()
}//end class
