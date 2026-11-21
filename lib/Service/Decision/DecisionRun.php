<?php

/**
 * OpenRegister DecisionRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\EntityRelation;
use OCP\IUser;

/**
 * One decide pass of RelationDecisionService (ADR-011 Rule 3 run object).
 *
 * `relation` is progress: it holds the relation as last written. The run
 * never leaves the service that built it.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class DecisionRun {

	/**
	 * Constructor.
	 *
	 * @param EntityRelation $relation   The relation, replaced by each write.
	 * @param IUser          $actor      The reviewer.
	 * @param string|null    $linkedUuid The decision uuid the relation named when the pass began.
	 * @param Decision|null  $current    The decision record that uuid resolves to, when it exists.
	 */
	public function __construct(
		public EntityRelation $relation,
		public readonly IUser $actor,
		public readonly ?string $linkedUuid,
		public readonly ?Decision $current,
	) {
	}//end __construct()
}//end class
