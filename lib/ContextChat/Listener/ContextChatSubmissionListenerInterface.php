<?php

/**
 * ContextChatSubmissionListenerInterface: the submission port the ContextChat content provider uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ContextChat
 * @package  OCA\OpenRegister\ContextChat\Listener
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

namespace OCA\OpenRegister\ContextChat\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Submits an object's content to Context Chat, as the content provider's batch walk uses it.
 *
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-opted-in-published-objects-are-submitted-to-context-chat
 */
interface ContextChatSubmissionListenerInterface {

	/**
	 * Submit an object's content when its schema is opted in and it satisfies the publication predicate.
	 *
	 * @param ObjectEntity $object The affected object (new state, for updates).
	 * @param Schema|null  $schema Optional pre-resolved schema (avoids a repeat lookup during a batch walk).
	 *
	 * @return bool True when content was actually submitted.
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-opted-in-published-objects-are-submitted-to-context-chat
	 */
	public function submitIfEligible(ObjectEntity $object, ?Schema $schema = null): bool;

	/**
	 * Remove an object's content from Context Chat when its schema is opted in.
	 *
	 * @param ObjectEntity $object The removed object; its uuid and schema are read.
	 * @param Schema|null  $schema Optional pre-resolved schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#requirement-only-opted-in-published-objects-are-submitted-to-context-chat
	 */
	public function remove(ObjectEntity $object, ?Schema $schema = null): void;
}//end interface
