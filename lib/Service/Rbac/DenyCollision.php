<?php

/**
 * One principal both granted and denied one verb at the same level.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * A grant-and-deny collision found by AuthorizationDenyValidator, and the sentence that refuses it.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class DenyCollision {

	/**
	 * Constructor.
	 *
	 * @param string $action    The verb granted and denied.
	 * @param string $principal The principal it is granted and denied to.
	 */
	public function __construct(
		public readonly string $action,
		public readonly string $principal,
	) {
	}//end __construct()

	/**
	 * The refusal, naming both rules.
	 *
	 * @param string $subject What the block belongs to.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function sentence(string $subject): string {
		return sprintf(
			'The authorization of %s both grants and denies "%s" to "%s" at the same level: '
			. 'the rule "%s" in %s and the rule "%s" in %s.deny. '
			. 'Remove one of the two; a deny wins, so leaving both stored would grant nothing '
			. 'and read as a grant.',
			$subject,
			$this->action,
			$this->principal,
			$this->principal,
			$this->action,
			$this->principal,
			$this->action
		);
	}//end sentence()
}//end class
