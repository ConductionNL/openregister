<?php

/**
 * OpenRegister Held Talk Call
 *
 * A call an example set declares as held in a Talk conversation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The conversation, who started the call, when it ran and who joined.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */
final class HeldTalkCall {

	/**
	 * Constructor.
	 *
	 * @param string               $token  The conversation token.
	 * @param string               $owner  The account that started the call.
	 * @param array<string, mixed> $call   The declared call: when it started (`start`) and ended (`end`).
	 * @param array<int, string>   $joined The accounts that joined.
	 */
	public function __construct(
		public readonly string $token,
		public readonly string $owner,
		public readonly array $call,
		public readonly array $joined,
	) {
	}//end __construct()
}//end class
