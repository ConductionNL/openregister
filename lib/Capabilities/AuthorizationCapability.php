<?php

/**
 * Authorization scope capability.
 *
 * Tells a client which kinds of access scope OpenRegister enforces, under
 * `openregister.authorization.scopes` in the Nextcloud capabilities document,
 * so a schema designer only offers a rule the engine will apply.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md#requirement-the-capabilities-document-states-the-enforced-scope-kinds
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCP\Capabilities\ICapability;

/**
 * The scope kinds this build enforces: groups, the owner sentinel and conditions.
 *
 * Registered in the same release as AuthorizationBlock::normalise(), so the
 * document never promises a kind the engine ignores (design D-3).
 *
 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md#requirement-the-capabilities-document-states-the-enforced-scope-kinds
 */
class AuthorizationCapability implements ICapability {
	/**
	 * The enforced scope kinds.
	 *
	 * @var list<string>
	 */
	public const SCOPES = ['group', 'creator', 'condition'];

	/**
	 * The authorization block under the `openregister` namespace.
	 *
	 * @return array{openregister: array{authorization: array{scopes: list<string>}}}
	 *
	 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md#requirement-the-capabilities-document-states-the-enforced-scope-kinds
	 */
	public function getCapabilities(): array {
		return [
			'openregister' => [
				'authorization' => [
					'scopes' => self::SCOPES,
				],
			],
		];
	}//end getCapabilities()
}//end class
