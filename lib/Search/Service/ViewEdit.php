<?php

/**
 * OpenRegister view edit
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Search\Service;

/**
 * One save of a saved view: what it is, who it is for, who favours it and its count alert.
 *
 * On an update a null favoredBy, presentation, sharedWith or alert leaves the stored value
 * untouched; on a create they mean "none".
 *
 * Not strict on purpose: it carries ViewDefinition and ViewAudience, which the views
 * controller fills from the request body.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final class ViewEdit {
	/**
	 * Describe a save.
	 *
	 * @param ViewDefinition $definition The name, description, query and presentation.
	 * @param ViewAudience   $audience   The owner, public and default flags and the group shares.
	 * @param array|null     $favoredBy  User IDs who favour the view; null leaves it untouched.
	 * @param array|null     $alert      The declared count alert; null leaves it untouched, `[]` clears it.
	 */
	public function __construct(
		public readonly ViewDefinition $definition,
		public readonly ViewAudience $audience,
		public readonly ?array $favoredBy=null,
		public readonly ?array $alert=null,
	) {
	}//end __construct()
}//end class
