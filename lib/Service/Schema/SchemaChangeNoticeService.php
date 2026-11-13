<?php

/**
 * Tells the people who build on a schema about its breaking changes.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\SchemaChangeNoticeJob;
use OCA\OpenRegister\Db\ApiCallRecordMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapperInterface;
use OCA\OpenRegister\Db\SchemaFollowerMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who builds on a schema: accounts that called its object routes in the last
 * 90 days, and users who follow it. The reach is shown on the breaking-change
 * gate; after an acknowledged breaking change a queued job tells each of them
 * once, if they can still read the schema, up to 500 recipients.
 *
 * The caller record collapses numeric ids to `{id}`, so only calls that named
 * the schema by its slug can be attributed to it; the register may be named
 * by slug or by id.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md
 */
class SchemaChangeNoticeService {

	/**
	 * The notification subject.
	 */
	public const SUBJECT = 'schema_breaking_change';

	/**
	 * How far back a call counts, in days.
	 */
	public const CALLER_DAYS = 90;

	/**
	 * The most recipients one change tells.
	 */
	public const RECIPIENT_CAP = 500;

	/**
	 * How many of the busiest callers the gate lists.
	 */
	public const TOP = 10;

	/**
	 * The longest note an administrator may add, in characters.
	 */
	public const NOTE_MAX = 500;

	/**
	 * The object routes as the caller record stores them.
	 */
	private const OBJECT_ROUTE = '/apps/openregister/api/objects/';

	/**
	 * Constructor.
	 *
	 * @param ApiCallRecordMapperInterface   $callers     The caller record.
	 * @param ApiCallRecorder                $recorder    Whether the caller record is on.
	 * @param SchemaFollowerMapper           $followers   The followers.
	 * @param SchemaChangelogMapperInterface $changelog   The changelog.
	 * @param RegisterMapperInterface        $registers   The registers that hold a schema.
	 * @param SchemaMapperInterface          $schemas     The schemas.
	 * @param PermissionCheckInterface       $permissions Whether a user may read a schema.
	 * @param IUserManager                   $users       Whether a user exists.
	 * @param IManager                       $notifications The notification channel.
	 * @param IJobList                       $jobs        The job queue.
	 * @param LoggerInterface                $logger      The log.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md
	 */
	public function __construct(
		private readonly ApiCallRecordMapperInterface $callers,
		private readonly ApiCallRecorder $recorder,
		private readonly SchemaFollowerMapper $followers,
		private readonly SchemaChangelogMapperInterface $changelog,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly PermissionCheckInterface $permissions,
		private readonly IUserManager $users,
		private readonly IManager $notifications,
		private readonly IJobList $jobs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Who a breaking change to the schema would reach, for the gate's 409.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{affectedCallers: array<string, mixed>, followers: int} The reach.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function reach(Schema $schema): array {
		$followers = $this->followers->countFollowers(schemaId: (int)$schema->getId());
		if ($this->recorder->isEnabled() === false) {
			return [
				'affectedCallers' => [
					'recorded' => false,
					'message' => 'The caller record is switched off, so callers of this schema are not known; only followers will be told.',
				],
				'followers' => $followers,
			];
		}

		$prefixes = $this->routePrefixes(schema: $schema);
		$from = $this->callerWindowStart();

		return [
			'affectedCallers' => [
				'recorded' => true,
				'count' => $this->callers->countCallersOfRoutes(prefixes: $prefixes, from: $from),
				'top' => array_map(
					static fn (array $caller): array => [
						'principal' => $caller['principal'],
						'calls' => $caller['calls'],
						'lastSeen' => $caller['lastSeen'],
					],
					$this->callers->findCallersOfRoutes(prefixes: $prefixes, from: $from, limit: self::TOP)
				),
			],
			'followers' => $followers,
		];
	}//end reach()

	/**
	 * Validate the administrator's note: plain text, at most 500 characters.
	 *
	 * @param mixed $note The submitted note.
	 *
	 * @return string|null The note, or null when none was given.
	 *
	 * @throws InvalidArgumentException When it is not a text or too long.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	public static function note(mixed $note): ?string {
		if ($note === null) {
			return null;
		}

		if (is_string($note) === false) {
			throw new InvalidArgumentException('changeNotice must be a text.');
		}

		$text = trim(strip_tags($note));
		if ($text === '') {
			return null;
		}

		if (mb_strlen($text) > self::NOTE_MAX) {
			throw new InvalidArgumentException(sprintf('changeNotice may be at most %d characters.', self::NOTE_MAX));
		}

		return $text;
	}//end note()

	/**
	 * Queue the notice for an applied, acknowledged breaking change.
	 *
	 * @param SchemaChangelog $entry The changelog entry.
	 * @param string|null     $note  The administrator's note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	public function queue(SchemaChangelog $entry, ?string $note): void {
		$this->jobs->add(SchemaChangeNoticeJob::class, ['changelogId' => (int)$entry->getId(), 'note' => $note]);
	}//end queue()

	/**
	 * Tell everyone who builds on the schema, once each, and record how many were told.
	 *
	 * @param int         $changelogId The changelog entry.
	 * @param string|null $note        The administrator's note.
	 *
	 * @return int How many were told.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	public function send(int $changelogId, ?string $note): int {
		$entry = $this->changelog->find($changelogId);
		$schema = $this->schemas->find(id: (int)$entry->getSchemaId(), _rbac: false, _multitenancy: false);

		$told = 0;
		foreach ($this->recipients(schema: $schema) as $uid) {
			if ($this->notify(uid: $uid, schema: $schema, entry: $entry, note: $note) === true) {
				$told++;
			}
		}

		$this->changelog->recordNotice(entry: $entry, count: $told);

		return $told;
	}//end send()

	/**
	 * The recipients: callers (not anonymous) then followers, once each, who
	 * exist and may read the schema, capped.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string> The user ids.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	public function recipients(Schema $schema): array {
		$candidates = [];
		if ($this->recorder->isEnabled() === true) {
			$callers = $this->callers->findCallersOfRoutes(
				prefixes: $this->routePrefixes(schema: $schema),
				from: $this->callerWindowStart(),
				limit: self::RECIPIENT_CAP
			);
			foreach ($callers as $caller) {
				$candidates[] = $caller['principal'];
			}
		}

		foreach ($this->followers->findUids(schemaId: (int)$schema->getId()) as $uid) {
			$candidates[] = $uid;
		}

		$recipients = [];
		foreach (array_unique($candidates) as $uid) {
			if (count($recipients) >= self::RECIPIENT_CAP) {
				break;
			}

			if ($uid !== '' && $this->mayRead(uid: $uid, schema: $schema) === true) {
				$recipients[] = $uid;
			}
		}

		return $recipients;
	}//end recipients()

	/**
	 * The route prefixes a call to the schema's objects is recorded under.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string> The prefixes.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function routePrefixes(Schema $schema): array {
		$schemaSlug = trim((string)$schema->getSlug());
		if ($schemaSlug === '') {
			return [];
		}

		// A register named by id is recorded as `{id}`.
		$prefixes = [self::OBJECT_ROUTE . '{id}/' . $schemaSlug];
		foreach ($this->registers->getAllRegisterIdsWithSchema((int)$schema->getId()) as $registerId) {
			try {
				$registerSlug = trim((string)$this->registers->find(id: (int)$registerId, _rbac: false, _multitenancy: false)->getSlug());
			} catch (Throwable $e) {
				continue;
			}

			if ($registerSlug !== '') {
				$prefixes[] = self::OBJECT_ROUTE . $registerSlug . '/' . $schemaSlug;
			}
		}

		return array_values(array_unique($prefixes));
	}//end routePrefixes()

	/**
	 * Whether a user exists and may read the schema; a failing check refuses.
	 *
	 * @param string $uid    The user.
	 * @param Schema $schema The schema.
	 *
	 * @return bool True when they may read it.
	 */
	private function mayRead(string $uid, Schema $schema): bool {
		if ($this->users->userExists($uid) === false) {
			return false;
		}

		try {
			return $this->permissions->hasPermission(schema: $schema, action: 'read', userId: $uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SchemaChangeNoticeService] Read check failed; the user is not told',
				context: ['uid' => $uid, 'schemaId' => $schema->getId(), 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end mayRead()

	/**
	 * Send one notification.
	 *
	 * @param string          $uid    The recipient.
	 * @param Schema          $schema The schema.
	 * @param SchemaChangelog $entry  The changelog entry.
	 * @param string|null     $note   The administrator's note.
	 *
	 * @return bool True when it was sent.
	 */
	private function notify(string $uid, Schema $schema, SchemaChangelog $entry, ?string $note): bool {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp('openregister')
				->setUser($uid)
				->setDateTime(new DateTime())
				->setObject('schema_changelog', (string)$entry->getId())
				->setSubject(
					self::SUBJECT,
					[
						'schemaId' => (int)$schema->getId(),
						'schema' => (string)($schema->getTitle() ?? $schema->getSlug() ?? $schema->getId()),
						'version' => (string)($entry->getVersion() ?? ''),
						'note' => (string)($note ?? ''),
					]
				);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SchemaChangeNoticeService] A breaking-change notice could not be sent',
				context: ['uid' => $uid, 'changelogId' => $entry->getId(), 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end notify()

	/**
	 * The start of the caller window.
	 *
	 * @return DateTime 90 days ago.
	 */
	private function callerWindowStart(): DateTime {
		return new DateTime(sprintf('-%d days', self::CALLER_DAYS));
	}//end callerWindowStart()
}//end class
