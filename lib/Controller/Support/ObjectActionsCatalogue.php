<?php

/**
 * OpenRegister object actions domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitionsInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionListInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The domain services of the object actions API.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */
final class ObjectActionsCatalogue {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface           $objects     Loads the subject.
	 * @param MacroActionResolver     $macros      Loads a schema; the `next` hint after a selection.
	 * @param ObjectActionDefinitionsInterface $definitions The schema's actions, for the selection surface rule.
	 * @param ObjectActionListInterface        $list        Lists the actions.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly MacroActionResolver $macros,
		public readonly ObjectActionDefinitionsInterface $definitions,
		public readonly ObjectActionListInterface $list,
	) {
	}//end __construct()
}//end class
