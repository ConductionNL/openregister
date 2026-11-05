<?php

/**
 * OpenRegister view patch
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

use OCA\OpenRegister\Search\Db\View;

/**
 * One PATCH of a saved view: the route id, the stored view and the request body.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final class ViewPatch {
	/**
	 * The view id from the route.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The stored view.
	 *
	 * @var View|null
	 */
	public ?View $view = null;

	/**
	 * The request parameters.
	 *
	 * @var array
	 */
	public array $data = [];
}//end class
