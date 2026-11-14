<?php

/**
 * The note-specific values of a `noteAdded` firing: who wrote it, what it says, who hears.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapperInterface;
use OCP\Comments\ICommentsManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds `note.author` and `note.excerpt` to the trigger context and drops the author from the recipients.
 *
 * The author of a user note is that user's display name; of a note left
 * through an access link, the link's label. The excerpt is the note's text
 * without markup, whitespace collapsed, cut at 140 characters. A value that
 * cannot be read is an empty string, so the placeholder renders empty rather
 * than the firing failing.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */
class NoteTriggerContext {

	/**
	 * The longest excerpt, in characters.
	 *
	 * @var int
	 */
	public const EXCERPT_LENGTH = 140;

	/**
	 * The actor type of an account author.
	 *
	 * @var string
	 */
	private const USER_ACTOR = 'users';

	/**
	 * The actor type of an access-link author.
	 *
	 * @var string
	 */
	private const LINK_ACTOR = 'openregister_links';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface       $logger   Records a note that could not be read.
	 * @param ICommentsManager|null $comments Where the note is stored.
	 * @param IUserManager|null     $users    The display name of a user author.
	 * @param AccessLinkMapperInterface|null $links    The label of a link author.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?ICommentsManager $comments = null,
		private readonly ?IUserManager $users = null,
		private readonly ?AccessLinkMapperInterface $links = null,
	) {
	}//end __construct()

	/**
	 * The context with `note.author` and `note.excerpt` added.
	 *
	 * @param array<string, mixed> $context The trigger context: `noteId`, `actorType`, `actorId`, `visibility`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
	 */
	public function enrich(array $context): array {
		$context['note.author']  = $this->author(
			type: (string)($context['actorType'] ?? ''),
			id: (string)($context['actorId'] ?? '')
		);
		$context['note.excerpt'] = self::excerpt(message: $this->message(noteId: (string)($context['noteId'] ?? '')));

		return $context;
	}//end enrich()

	/**
	 * The recipients without the note's author, when the author is an account.
	 *
	 * @param array<int, string>   $uids    The resolved recipients.
	 * @param array<string, mixed> $context The trigger context.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-watcher-hears-about-a-new-comment
	 */
	public function withoutAuthor(array $uids, array $context): array {
		if ((string)($context['actorType'] ?? '') !== self::USER_ACTOR) {
			return $uids;
		}

		$author = (string)($context['actorId'] ?? '');

		return array_values(array_filter($uids, static fn (string $uid): bool => $uid !== $author));
	}//end withoutAuthor()

	/**
	 * A message as plain text of at most 140 characters.
	 *
	 * @param string $message The stored note.
	 *
	 * @return string
	 */
	public static function excerpt(string $message): string {
		$plain = trim((string)preg_replace('/\s+/u', ' ', strip_tags($message)));
		if (mb_strlen($plain) <= self::EXCERPT_LENGTH) {
			return $plain;
		}

		return rtrim(mb_substr($plain, 0, (self::EXCERPT_LENGTH - 1))).'…';
	}//end excerpt()

	/**
	 * The author's name: a user's display name, a link's label, else the actor id.
	 *
	 * @param string $type The actor type.
	 * @param string $id   The actor id.
	 *
	 * @return string
	 */
	private function author(string $type, string $id): string {
		if ($type === self::USER_ACTOR) {
			$name = $this->users?->getDisplayName($id);
			if (is_string($name) === true && $name !== '') {
				return $name;
			}

			return $id;
		}

		if ($type === self::LINK_ACTOR && str_starts_with($id, AccessLink::PRINCIPAL_PREFIX) === true) {
			return ($this->linkName(uuid: substr($id, strlen(AccessLink::PRINCIPAL_PREFIX))) ?? $id);
		}

		return $id;
	}//end author()

	/**
	 * The label of the link with this uuid, or null when it cannot be found.
	 *
	 * @param string $uuid The link uuid.
	 *
	 * @return string|null
	 */
	private function linkName(string $uuid): ?string {
		try {
			return $this->links?->findByUuid(uuid: $uuid)?->principalName();
		} catch (Throwable $e) {
			$this->logger->info(sprintf('[NoteTriggerContext] link author not found: %s', $e->getMessage()));
			return null;
		}
	}//end linkName()

	/**
	 * The stored text of the note, or an empty string.
	 *
	 * @param string $noteId The note id.
	 *
	 * @return string
	 */
	private function message(string $noteId): string {
		if ($noteId === '' || $this->comments === null) {
			return '';
		}

		try {
			return $this->comments->get($noteId)->getMessage();
		} catch (Throwable $e) {
			$this->logger->info(sprintf('[NoteTriggerContext] note %s not readable: %s', $noteId, $e->getMessage()));
			return '';
		}
	}//end message()
}//end class
