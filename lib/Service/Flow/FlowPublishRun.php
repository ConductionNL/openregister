<?php

/**
 * One publish of a flow's head, inside FlowVersionService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;

/**
 * The flow being published, how, and what the publish has worked out so far.
 *
 * Built by {@see FlowVersionService::publish()} and never leaves that class.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
final class FlowPublishRun {

	/**
	 * The graph being published.
	 *
	 * @var array
	 */
	public array $graph = [];

	/**
	 * The definition hash the graph was pinned under.
	 *
	 * @var string
	 */
	public string $hash = '';

	/**
	 * The semantic version derived for this publish.
	 *
	 * @var string
	 */
	public string $semver = '';

	/**
	 * Constructor.
	 *
	 * @param Flow               $flow    The flow whose head is being published.
	 * @param FlowPublishOptions $options Who publishes, and the bump asked for.
	 */
	public function __construct(
		public readonly Flow $flow,
		public readonly FlowPublishOptions $options,
	) {
	}//end __construct()
}//end class
