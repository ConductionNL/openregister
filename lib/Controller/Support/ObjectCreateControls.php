<?php

/**
 * OpenRegister ObjectCreateControls
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


use OCP\IRequest;

/**
 * The controls of a create, read from the raw request because the body filter strips `_`-prefixed keys.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectCreateControls {
	/**
	 * Insert only: an identifier already taken answers 409 instead of overwriting.
	 *
	 * @var boolean
	 */
	public readonly bool $failIfExists;

	/**
	 * Asks for a duplicate override. The save path decides against the schema's `overrideGroups`.
	 *
	 * @var boolean
	 */
	public readonly bool $dedupOverride;

	/**
	 * The uniqueness constraint to upsert on, or an empty string.
	 *
	 * @var string
	 */
	public readonly string $upsertOn;

	/**
	 * Read the controls.
	 *
	 * @param IRequest    $request The request.
	 * @param WriteCaller $caller  The caller's scope and uploads.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		IRequest $request,
		public readonly WriteCaller $caller,
	) {
		$this->failIfExists = filter_var($request->getParam('_failIfExists', false), FILTER_VALIDATE_BOOLEAN);
		$this->dedupOverride = filter_var($request->getParam('_dedupOverride', false), FILTER_VALIDATE_BOOLEAN);
		$this->upsertOn = trim((string)$request->getParam('_upsertOn', ''));
	}//end __construct()
}//end class
