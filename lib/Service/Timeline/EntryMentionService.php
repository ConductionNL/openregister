<?php

/**
 * EntryMentionService: naming somebody in an entry pulls them in, and keeps
 * them in.
 *
 * Naming a colleague in a note is how work actually moves between desks. Today
 * it produces a notification and nothing else, so the colleague reads the note
 * once and never hears about the case again. Subscribing them is the same act
 * as watching, which is why this uses the watcher primitive rather than a
 * second subscription model (D-7).
 *
 * THE RULE THAT MATTERS: a mention cannot grant access. A principal who may not
 * read the object is neither notified nor subscribed, and not because the
 * notification would be empty — because naming somebody is otherwise a way to
 * tell them a case exists, who is on it and what it is called.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Timeline;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mentions in a timeline entry.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class EntryMentionService {

	/**
	 * The subject the notifier renders a mention under.
	 *
	 * @var string
	 */
	public const SUBJECT = 'timeline_mention';

	/**
	 * The token a mention is written as.
	 *
	 * Deliberately narrow. A uid may hold letters, digits, dot, dash and
	 * underscore; anything else ends the token. The lookbehind keeps an e-mail
	 * address out: `info@conduction.nl` is not a mention of `conduction`.
	 *
	 * @var string
	 */
	private const TOKEN = '/(?<![\p{L}\p{N}._@-])@([\p{L}\p{N}][\p{L}\p{N}._-]{0,62})/u';

	/**
	 * Constructor.
	 *
	 * @param WatcherService        $watchers      Subscribes the named principal.
	 * @param IUserManager          $userManager   Resolves a token to a real principal.
	 * @param IUserSession          $userSession   The caller, who is never notified of their own mention.
	 * @param SchemaMapper          $schemaMapper  Resolves the object's schema for the read check.
	 * @param PermissionHandler     $permissions   The one RBAC evaluator.
	 * @param INotificationManager  $notifications Sends the notification.
	 * @param LoggerInterface       $logger        Logger for the fail-closed paths.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly WatcherService $watchers,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly SchemaMapper $schemaMapper,
		private readonly PermissionHandler $permissions,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uids a text names.
	 *
	 * Resolution to a real principal happens here, so a sentence about
	 * "@home" produces nothing rather than a notification nobody receives.
	 *
	 * @param string|null $text The entry text.
	 *
	 * @return array<int,string> The uids named, each once, in the order written.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function parse(?string $text): array {
		if (is_string($text) === false || trim($text) === '') {
			return [];
		}

		$matches = [];
		if (preg_match_all(self::TOKEN, $text, $matches) === false) {
			return [];
		}

		$uids = [];
		foreach ($matches[1] as $candidate) {
			$uid = (string)$candidate;
			if (in_array($uid, $uids, true) === true) {
				continue;
			}

			if ($this->userManager->userExists($uid) === false) {
				continue;
			}

			$uids[] = $uid;
		}

		return $uids;
	}//end parse()

	/**
	 * Notify and subscribe everybody the entry named and who may read the object.
	 *
	 * Returns the uids that were actually pulled in, which is what the caller
	 * reports and what a test asserts on: a method that returned the uids it
	 * was ASKED about would pass whether the access check worked or not.
	 *
	 * @param ObjectEntity $object    The object the entry hangs on.
	 * @param string       $entryUuid The entry that named them.
	 * @param string|null  $text      The entry text.
	 * @param string|null  $register  The register as the caller addressed it.
	 * @param string|null  $schema    The schema as the caller addressed it.
	 *
	 * @return array<int,string> The uids notified and subscribed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function apply(
		ObjectEntity $object,
		string $entryUuid,
		?string $text,
		?string $register = null,
		?string $schema = null,
	): array {
		$caller = $this->userSession->getUser()?->getUID();
		$pulled = [];

		foreach ($this->parse(text: $text) as $uid) {
			if ($uid === $caller) {
				// Naming yourself is not a mention. It notifies you of your
				// own sentence and subscribes you to a case you are on.
				continue;
			}

			if ($this->mayRead(object: $object, userId: $uid) === false) {
				continue;
			}

			try {
				$this->watchers->subscribeMentioned(
					object: $object,
					userId: $uid,
					register: $register,
					schema: $schema
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[EntryMentionService] '.$uid.' was named in entry '.$entryUuid
						.' but could not be subscribed',
					['exception' => $e]
				);
				continue;
			}

			$this->notify(object: $object, uid: $uid, entryUuid: $entryUuid, author: $caller);
			$pulled[] = $uid;
		}//end foreach

		return $pulled;
	}//end apply()

	/**
	 * Whether a named principal may read the object at all.
	 *
	 * Fails CLOSED. A schema that cannot be resolved is a refusal, never an
	 * allow: the whole value of this check is that it is the thing standing
	 * between a mention and an object the mentioned principal cannot see.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $userId The named principal.
	 *
	 * @return boolean True when they may read it.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function mayRead(ObjectEntity $object, string $userId): bool {
		try {
			$schema = $this->schemaMapper->find(
				id: (string)$object->getSchema(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[EntryMentionService] Schema for object '.(string)$object->getUuid()
					.' could not be resolved; refusing the mention',
				['exception' => $e]
			);

			return false;
		}

		return $this->permissions->hasPermission(
			schema: $schema,
			action: 'read',
			userId: $userId,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);
	}//end mayRead()

	/**
	 * Tell the named principal they were named.
	 *
	 * A failure here is logged and swallowed. The subscription has already
	 * been written, and losing the whole entry because a notification backend
	 * was briefly unreachable would be a worse trade than a missing popup.
	 *
	 * @param ObjectEntity $object    The object.
	 * @param string       $uid       The named principal.
	 * @param string       $entryUuid The entry.
	 * @param string|null  $author    Who named them.
	 *
	 * @return void
	 */
	private function notify(ObjectEntity $object, string $uid, string $entryUuid, ?string $author): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp('openregister')
				->setUser($uid)
				->setDateTime(new DateTime())
				->setObject('timeline_entry', $entryUuid)
				->setSubject(
					self::SUBJECT,
					[
						'objectUuid' => (string)$object->getUuid(),
						'objectTitle' => (string)($object->getName() ?? $object->getUuid()),
						'registerId' => $object->getRegister(),
						'schemaId' => $object->getSchema(),
						'entryUuid' => $entryUuid,
						'author' => ($author ?? ''),
					]
				);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[EntryMentionService] '.$uid.' was subscribed through entry '.$entryUuid
					.' but could not be notified',
				['exception' => $e]
			);
		}
	}//end notify()
}//end class
