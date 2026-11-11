<?php

/**
 * DenialQuestion - one question put to the deny rules of a block
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The block, verb, principals and object a denial is looked up for.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class DenialQuestion {

	/**
	 * The principals of the caller.
	 *
	 * @var array
	 */
	public array $principals = [];

	/**
	 * The object, when there is one; only then can a conditional deny apply.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;

	/**
	 * Create the question.
	 *
	 * @param array|null $authorization The cascaded block.
	 * @param string     $action        The verb.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly ?array $authorization,
		public readonly string $action,
	) {
	}//end __construct()

	/**
	 * Name who asks about what.
	 *
	 * @param array             $principals The principals of the caller.
	 * @param ObjectEntity|null $object     The object.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function about(array $principals, ?ObjectEntity $object): self {
		$this->principals = $principals;
		$this->object     = $object;

		return $this;
	}//end about()
}//end class
