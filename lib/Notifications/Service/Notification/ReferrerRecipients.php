<?php

/**
 * Resolves a `referrers` recipient: the people behind the objects that point at the triggering one.
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
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-address-the-people-behind-referring-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use Closure;
use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds the referring objects, resolves the nested block against each, and keeps the readers.
 *
 * The referred objects are the triggering object, or the objects its `of`
 * property points at (at most 10). The referring objects are those of the
 * named register and schema whose named property holds a referred uuid, read
 * without the actor's RBAC because the rule is the schema author's
 * declaration; at most 200 are used and a rule that stops there says so in
 * an unresolved `referrers-truncated` entry. Every uid the nested block
 * reaches must then pass read on the triggering object, and nothing of a
 * referring object is rendered, so its content never reaches a recipient.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-address-the-people-behind-referring-objects
 */
class ReferrerRecipients {

	/**
	 * The most objects an `of` property contributes.
	 *
	 * @var int
	 */
	public const MAX_REFERRED = 10;

	/**
	 * The most referring objects one entry resolves against.
	 *
	 * @var int
	 */
	public const MAX_REFERRERS = 200;

	/**
	 * The kind an unresolved entry of this resolver is recorded under.
	 *
	 * @var string
	 */
	private const KIND = 'referrers';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface      $registers Resolves the named register.
	 * @param SchemaMapperInterface        $schemas   Resolves the named schema and the triggering object's schema.
	 * @param MagicRelationReaderInterface $relations Reads the referring objects.
	 * @param ReadableRecipients           $readable  The read check on the triggering object.
	 * @param LoggerInterface              $logger    Records a lookup that failed.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly MagicRelationReaderInterface $relations,
		private readonly ReadableRecipients $readable,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uids a `referrers` entry reaches, and what did not resolve.
	 *
	 * @param array<string, mixed> $entry  The `referrers` entry.
	 * @param RecipientSource      $source The triggering object, its data and the trigger context.
	 * @param Closure              $nested Resolves a recipients block against a source:
	 *                                     fn (array, RecipientSource): array{uids: array, unresolved: array}.
	 *
	 * @return array{uids: array<int, string>, unresolved: array<int, array{kind: string, id: string, reason: string}>}
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-organisations-using-an-application-hear-about-a-new-version
	 */
	public function resolve(array $entry, RecipientSource $source, Closure $nested): array {
		$object = $source->object;
		if ($object === null) {
			return ['uids' => [], 'unresolved' => []];
		}

		$location = $this->location(entry: $entry);
		if (is_array($location) === false) {
			return ['uids' => [], 'unresolved' => [$this->unresolved(id: (string)$location, reason: 'register or schema not found')]];
		}

		$referred = $this->referredUuids(entry: $entry, source: $source);
		$found    = $this->referrers(uuids: $referred, location: $location);

		$uids       = [];
		$unresolved = [];
		if ($found['total'] > self::MAX_REFERRERS) {
			$unresolved[] = $this->unresolved(
				id: 'referrers-truncated',
				reason: sprintf('stopped at %d of %d referring objects', self::MAX_REFERRERS, $found['total'])
			);
		}

		$recipients = (array)($entry['recipients'] ?? []);
		foreach ($found['objects'] as $referrer) {
			$resolved = $nested(
				$recipients,
				new RecipientSource(data: ($referrer->getObject() ?? []), object: $referrer, context: $source->context, roleGroups: [])
			);
			array_push($uids, ...$resolved['uids']);
			array_push($unresolved, ...$resolved['unresolved']);
		}

		return [
			'uids' => $this->readers(uids: array_values(array_unique($uids)), object: $object),
			'unresolved' => $unresolved,
		];
	}//end resolve()

	/**
	 * The register and schema ids the entry names, or the name that did not resolve.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array{register: int, schema: int, property: string}|string
	 */
	private function location(array $entry): array|string {
		$register = (string)($entry['register'] ?? '');
		$schema   = (string)($entry['schema'] ?? '');
		$property = (string)($entry['property'] ?? '');
		if ($register === '' || $schema === '' || $property === '') {
			return $register.'/'.$schema;
		}

		try {
			$registerId = (int)$this->registers->find(id: $register, _rbac: false, _multitenancy: false)->getId();
			$schemaId   = (int)$this->schemas->find(id: $schema, _extend: [], _rbac: false, _multitenancy: false)->getId();
		} catch (Throwable $e) {
			$this->logger->info(sprintf('[ReferrerRecipients] %s/%s not found: %s', $register, $schema, $e->getMessage()));
			return $register.'/'.$schema;
		}

		return ['register' => $registerId, 'schema' => $schemaId, 'property' => $property];
	}//end location()

	/**
	 * The uuids the referring objects must point at.
	 *
	 * @param array<string, mixed> $entry  The entry.
	 * @param RecipientSource      $source The triggering object and its data.
	 *
	 * @return array<int, string>
	 */
	private function referredUuids(array $entry, RecipientSource $source): array {
		$of = $entry['of'] ?? null;
		if (is_string($of) === false || $of === '') {
			return array_filter([(string)($source->object?->getUuid() ?? '')], static fn (string $uuid): bool => $uuid !== '');
		}

		$value = ($source->data[$of] ?? null);
		$uuids = array_values(array_unique(array_filter((array)$value, static fn ($uuid): bool => is_string($uuid) === true && $uuid !== '')));

		return array_slice($uuids, 0, self::MAX_REFERRED);
	}//end referredUuids()

	/**
	 * The referring objects, at most 200, and how many there were.
	 *
	 * @param array<int, string>                                  $uuids    The referred uuids.
	 * @param array{register: int, schema: int, property: string} $location Where the referring objects live.
	 *
	 * @return array{objects: array<int, ObjectEntity>, total: int}
	 */
	private function referrers(array $uuids, array $location): array {
		$byUuid = [];
		foreach ($uuids as $uuid) {
			try {
				$rows = $this->relations->findByRelationBatchInSchema(
					uuids: [$uuid],
					schemaId: $location['schema'],
					registerId: $location['register'],
					fieldName: $location['property']
				);
			} catch (Throwable $e) {
				$this->logger->warning(sprintf('[ReferrerRecipients] referring objects not read: %s', $e->getMessage()));
				continue;
			}

			foreach ($rows as $row) {
				if (self::pointsAt(row: $row, property: $location['property'], uuid: $uuid) === true) {
					$byUuid[(string)$row->getUuid()] = $row;
				}
			}
		}

		return ['objects' => array_slice(array_values($byUuid), 0, self::MAX_REFERRERS), 'total' => count($byUuid)];
	}//end referrers()

	/**
	 * Whether the row's property holds the uuid, as a value or in a list.
	 *
	 * @param ObjectEntity $row      The candidate referring object.
	 * @param string       $property The property that points back.
	 * @param string       $uuid     The referred uuid.
	 *
	 * @return bool
	 */
	private static function pointsAt(ObjectEntity $row, string $property, string $uuid): bool {
		$value = (($row->getObject() ?? [])[$property] ?? null);
		if (is_array($value) === true) {
			return in_array($uuid, $value, true);
		}

		return $value === $uuid;
	}//end pointsAt()

	/**
	 * The uids that may read the triggering object; nobody when its schema cannot be found.
	 *
	 * @param array<int, string> $uids   The reached uids.
	 * @param ObjectEntity       $object The triggering object.
	 *
	 * @return array<int, string>
	 */
	private function readers(array $uids, ObjectEntity $object): array {
		if ($uids === []) {
			return [];
		}

		try {
			$schema = $this->schemas->find(id: (string)$object->getSchema(), _extend: [], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('[ReferrerRecipients] schema of the triggering object not found: %s', $e->getMessage()));
			return [];
		}

		return $this->readable->filter(uids: $uids, object: $object, schema: $schema);
	}//end readers()

	/**
	 * An unresolved entry of this kind.
	 *
	 * @param string $id     What did not resolve.
	 * @param string $reason Why.
	 *
	 * @return array{kind: string, id: string, reason: string}
	 */
	private function unresolved(string $id, string $reason): array {
		return ['kind' => self::KIND, 'id' => $id, 'reason' => $reason];
	}//end unresolved()
}//end class
