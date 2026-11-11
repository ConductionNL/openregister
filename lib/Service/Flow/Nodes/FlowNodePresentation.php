<?php

/**
 * What a flow step shows its author: translated labels and its palette icon.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * What a flow step shows its author: translated labels and its palette icon.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-carries-the-behaviour-and-an-edge-carries-only-sequence
 */
final class FlowNodePresentation {
	/**
	 * Bundle the services.
	 *
	 * @param IL10N         $l10n Translations for labels, help texts and refusals.
	 * @param IURLGenerator $urls Resolves the palette icon.
	 */
	public function __construct(
		public readonly IL10N $l10n,
		public readonly IURLGenerator $urls,
	) {
	}//end __construct()
}//end class
