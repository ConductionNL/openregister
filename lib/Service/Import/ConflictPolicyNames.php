<?php

/**
 * ConflictPolicyNames: the conflict-policy names ConflictPolicy decides by, for callers outside lib/Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

/**
 * The conflict-policy names an import accepts, for callers outside lib/Service that list or default them.
 */
interface ConflictPolicyNames {

	/**
	 * Every row creates. A row that matches an existing object is refused,
	 * because on a first migration a match means the key is wrong.
	 *
	 * @var string
	 */
	public const CREATE_ONLY = 'create-only';

	/**
	 * Only matched rows are written. A row that matches nothing is skipped.
	 *
	 * @var string
	 */
	public const UPDATE_ONLY = 'update-only';

	/**
	 * A matched row updates, an unmatched row creates.
	 *
	 * @var string
	 */
	public const UPSERT = 'upsert';

	/**
	 * A matched row is refused. Unmatched rows create.
	 *
	 * @var string
	 */
	public const REFUSE_ON_CONFLICT = 'refuse-on-conflict';

	/**
	 * The policy an import that declares none runs under, which is what the
	 * import path did before this change.
	 *
	 * @var string
	 */
	public const DEFAULT_POLICY = self::UPSERT;

	/**
	 * Every policy, for validation and for the catalogue a client reads.
	 *
	 * @var array<int, string>
	 */
	public const POLICIES = [
		self::CREATE_ONLY,
		self::UPDATE_ONLY,
		self::UPSERT,
		self::REFUSE_ON_CONFLICT,
	];
}//end interface
