<?php

/**
 * OpenRegister Example Set Timeline Entries
 *
 * The timeline an example set gives its objects: a fragment's
 * `timelineEntries` part names the object, the entry's kind (a declared
 * timeline kind), its message, when, and optionally the person who wrote it
 * (none is the system), its fields and visibility. Each row becomes one
 * timeline entry at that moment, its fields checked against the kind's declaration,
 * and marked with the source `example-set:<app>/<key>` so a second install
 * finds it and writes nothing. Remove deletes the entry.
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
 * @spec openspec/changes/example-set-timeline-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Db\TimelineEntryMapper;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\Timeline\TimelineKindService;
use Psr\Container\ContainerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Checks, writes, proves and deletes the timeline entries of an example set.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 gives the entry the uuid every timeline row carries.
 *
 * @spec openspec/changes/example-set-timeline-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
 */
class ExampleSetTimelineEntries extends ExampleSetEngineRows {

	/**
	 * The entry fields a fragment may set.
	 */
	public const FIELDS = ['object', 'kind', 'message', 'author', 'at', 'fields', 'visibility'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the entry mapper, the kind service and the object finder.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part entries live in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-timeline-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function part(): string {
		return 'timelineEntries';
	}//end part()

	/**
	 * What one row is called in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-timeline-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function noun(): string {
		return 'timeline entry';
	}//end noun()

	/**
	 * Why a row cannot be written, '' when it can.
	 *
	 * @param array<string, mixed> $data  The row's fields, placeholders filled.
	 * @param array<int, string>   $users The accounts the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-timeline-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a timeline entry field a set may write';
		}

		$required = ['object' => 'the object it is on', 'kind' => 'a kind', 'message' => 'a message', 'at' => 'a moment'];
		foreach ($required as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return 'a timeline entry needs '.$what.' ('.$field.')';
			}
		}

		$shape = $this->shapeRefusal(data: $data, users: $users);
		if ($shape !== '') {
			return $shape;
		}

		try {
			new DateTime((string) $data['at']);
		} catch (Throwable) {
			return 'at "'.(string) $data['at'].'" is not a date and time';
		}

		return '';
	}//end refusal()

	/**
	 * Why the author or the fields cannot be written, '' when they can.
	 *
	 * @param array<string, mixed> $data  The row's fields.
	 * @param array<int, string>   $users The accounts the set creates or adopts.
	 *
	 * @return string
	 */
	private function shapeRefusal(array $data, array $users): string {
		if (isset($data['author']) === true && in_array((string) $data['author'], $users, true) === false) {
			return '"'.(string) $data['author'].'" is not an account the set creates or adopts';
		}

		if (isset($data['fields']) === true && is_array($data['fields']) === false) {
			return 'fields is an object';
		}

		return '';
	}//end shapeRefusal()

	/**
	 * Write one entry by the named person, or find the one an earlier install wrote.
	 *
	 * @param string               $app  The fragment's app.
	 * @param string               $key  The row key.
	 * @param array<string, mixed> $data The row's fields, placeholders filled.
	 *
	 * @return array{error: string, entry: array{uuid: string, object: string}|null} The record entry, or why the
	 *                                                                                write failed.
	 *
	 * @spec openspec/changes/example-set-timeline-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function write(string $app, string $key, array $data): array {
		$objectUuid = (string) $data['object'];
		$object     = $this->container->get(PartyService::class)->findUnscoped(uuid: $objectUuid);
		if ($object === null) {
			return ['error' => 'no object "'.$objectUuid.'" to give the entry', 'entry' => null];
		}

		$source = 'example-set:'.$app.'/'.$key;
		$mapper = $this->mapper();
		try {
			foreach ($mapper->findForObject($objectUuid, null, 500) as $earlier) {
				if ($earlier->getRawSource() === $source) {
					return ['error' => '', 'entry' => ['uuid' => (string) $earlier->getUuid(), 'object' => $objectUuid]];
				}
			}

			$kind   = (string) $data['kind'];
			$moment = new DateTime((string) $data['at']);
			$fields = $this->container->get(TimelineKindService::class)->validateFields(kindSlug: $kind, fields: (array) ($data['fields'] ?? []));
			$entry  = new TimelineEntry();
			$entry->setUuid((string) Uuid::v4());
			$entry->setObjectUuid($objectUuid);
			$entry->setRegister((string) $object->getRegister());
			$entry->setSchema((string) $object->getSchema());
			$entry->setKind($kind);
			$entry->setAuthor($this->author(data: $data));
			$entry->setVisibility((string) ($data['visibility'] ?? 'internal'));
			$entry->setMessage((string) $data['message']);
			$entry->setFields($fields);
			$entry->setPinned(false);
			$entry->setRawSource($source);
			$entry->setCreated($moment);
			$entry->setUpdated($moment);
			$written = $mapper->insert($entry);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => (string) $written->getUuid(), 'object' => $objectUuid]];
	}//end write()

	/**
	 * Whether a written entry is gone.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-timeline-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function lost(array $entry): bool {
		try {
			return $this->mapper()->findByUuid((string) $entry['uuid']) === null;
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Delete the entry the set wrote.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return string `removed` or `failed`.
	 *
	 * @spec openspec/changes/example-set-timeline-entries/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function revert(array $entry): string {
		try {
			$found = $this->mapper()->findByUuid((string) $entry['uuid']);
			if ($found !== null) {
				$this->mapper()->delete($found);
			}
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The entry's author: the declared account, or null for an entry the system wrote, as a write without a session
	 * leaves it.
	 *
	 * @param array<string, mixed> $data The row's fields.
	 *
	 * @return string|null
	 */
	private function author(array $data): ?string {
		if (isset($data['author']) === false) {
			return null;
		}

		return (string) $data['author'];
	}//end author()

	/**
	 * The timeline entry mapper.
	 *
	 * @return TimelineEntryMapper
	 */
	private function mapper(): TimelineEntryMapper {
		return $this->container->get(TimelineEntryMapper::class);
	}//end mapper()
}//end class
