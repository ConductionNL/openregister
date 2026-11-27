<?php

/**
 * ReplyThreadResolutionInterface: the published, granted resolve of a reply's
 * headers onto the object it answers.
 *
 * A mail intake running as a granted, run-scoped identity (ADR-099) decides
 * where an inbound reply is filed, so it needs the answer whoever may read
 * the case. It holds this interface in-process; no route reaches it, and a
 * user calling `POST /api/emails/resolve` keeps the user-scoped answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Resolves `In-Reply-To` and `References` across RBAC.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
interface ReplyThreadResolutionInterface {

	/**
	 * Which object a reply threads onto, across every object.
	 *
	 * `In-Reply-To` is matched first, then `References` from its last entry;
	 * nothing is guessed and the subject is never read. Two objects named
	 * resolve neither.
	 *
	 * @param array<string, mixed> $headers `In-Reply-To` (string) and `References` (string or list of ids).
	 *
	 * @return array<string, mixed> `state` (`threaded`, `ambiguous` or `unthreaded`), the object or the
	 *         candidates, the header that matched, and `scope: system`.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function resolveAsSystem(array $headers): array;
}//end interface
