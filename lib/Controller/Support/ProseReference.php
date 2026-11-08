<?php

/**
 * OpenRegister prose reference
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One reference written in prose: the object the text belongs to, the object
 * it names and the anchor that identifies the text.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ProseReference {
	/**
	 * Name both ends of a prose reference.
	 *
	 * @param ObjectEntity $source The object the text belongs to.
	 * @param ObjectEntity $far    The object the text names, already read under the caller's RBAC.
	 * @param string       $anchor What identifies the text, so it can be withdrawn.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly ObjectEntity $far,
		public readonly string $anchor,
	) {
	}//end __construct()
}//end class
