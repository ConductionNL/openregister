<?php

/**
 * A run's subject as it stands now, and the uuid the run knows it by.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The live subject a resumed run refreshes its items from
 * ({@see FlowSubjectItems::refreshSubjectProjection()}).
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md#requirement-resuming-carries-the-runs-own-items-req-fr-003
 */
final class LiveSubject {
	/**
	 * Constructor.
	 *
	 * @param object $subject The live subject.
	 * @param string $uuid    The run's subject uuid, as stored.
	 */
	public function __construct(
		public readonly object $subject,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
