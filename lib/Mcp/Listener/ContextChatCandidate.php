<?php

/**
 * ContextChatCandidate — an object whose schema opted in to Context Chat
 * indexing, together with that schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-opted-in-published-objects-are-submitted-to-context-chat
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * An opted-in object and the schema it was resolved against.
 *
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-opted-in-published-objects-are-submitted-to-context-chat
 */
final class ContextChatCandidate {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema       $schema The object's (opted-in) schema.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
