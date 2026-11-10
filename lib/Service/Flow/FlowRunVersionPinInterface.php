<?php

/**
 * FlowRunVersionPinInterface: what callers outside the component use of FlowRunVersionPin.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The run trigger the flow endpoints name when they start a test run.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FlowRunVersionPinInterface {

	/**
	 * The trigger an interactive test run carries.
	 *
	 * 🔑 THE ONE DISPATCH THAT MAY WALK A DRAFT. Every other path is a live
	 * process acting on real data and must run a published version. A test run
	 * is an author pressing "try this" on the graph in front of them — refusing
	 * it would mean a flow could not be tried until it was published, which is
	 * exactly backwards: publishing is the thing you do AFTER testing.
	 *
	 * @var string
	 */
	public const TRIGGER_TEST = 'test';
}//end interface
