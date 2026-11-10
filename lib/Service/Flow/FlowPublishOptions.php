<?php

/**
 * How a flow's head is published: who publishes it and which bump they ask for.
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

/**
 * The optional parts of a publish.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
final class FlowPublishOptions {
	/**
	 * Constructor.
	 *
	 * @param string|null $publishedBy The acting user, null when code-initiated.
	 * @param string|null $bump        The semantic-version bump requested, null to derive it.
	 */
	public function __construct(
		public readonly ?string $publishedBy = null,
		public readonly ?string $bump = null,
	) {
	}//end __construct()
}//end class
