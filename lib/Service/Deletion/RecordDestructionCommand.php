<?php

/**
 * RecordDestructionCommand — one destruction to write down before it happens.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * What a destruction record states: the object, what went with it, the rule
 * the act ran under and anything else worth keeping.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class RecordDestructionCommand {
	/**
	 * Name the destruction.
	 *
	 * @param ObjectEntity         $object  The object about to be destroyed.
	 * @param array<string, mixed> $scope   The scope report from DestructionScopeService.
	 * @param string               $rule    The rule the act ran under, e.g. `destroy-right-granted`.
	 * @param array<string, mixed> $context Anything else worth keeping, such as the window it ran after.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly array $scope,
		public readonly string $rule,
		public readonly array $context=[],
	) {
	}//end __construct()
}//end class
