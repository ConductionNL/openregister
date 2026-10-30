<?php

/**
 * OpenRegister Example Set Case Plans
 *
 * The adaptive case plans an example set puts on its objects: a fragment's
 * `casePlans` part names the object, the plan's definition (its stages,
 * tasks and milestones, each with the state it is in) and the history of how
 * it got there. The items are brought in through the case layer's convergent
 * import as the fragment's app, so a second install creates nothing; remove
 * deletes the items the set created.
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
 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the case plans of an example set.
 *
 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
 */
class ExampleSetCasePlans extends ExampleSetEngineRows {
	/**
	 * The plan fields a fragment may set.
	 */
	public const FIELDS = ['object', 'definition', 'history'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the case plan service, the item and task mappers and the object finder on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part plans live in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function part(): string {
		return 'casePlans';
	}//end part()

	/**
	 * The word for one plan, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function noun(): string {
		return 'case plan';
	}//end noun()

	/**
	 * Why a filled plan cannot be written, or ''.
	 *
	 * The definition itself is checked by the case layer when it is written,
	 * before any item is stored.
	 *
	 * @param array<string, mixed> $data  The filled plan fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts (unused: a plan is the app's).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a case plan field a set may write';
		}

		if (is_string($data['object'] ?? null) === false || trim($data['object']) === '') {
			return 'a case plan needs the object it is on (object)';
		}

		if ($this->hasItems(definition: ($data['definition'] ?? null)) === false) {
			return 'a case plan needs a definition with its items (definition.items)';
		}

		if (isset($data['history']) === true && (is_array($data['history']) === false || array_is_list($data['history']) === false)) {
			return 'history is a list';
		}

		return '';
	}//end refusal()

	/**
	 * Whether a definition lists at least one item.
	 *
	 * @param mixed $definition The declared definition.
	 *
	 * @return bool
	 */
	private function hasItems(mixed $definition): bool {
		return is_array($definition) === true && is_array($definition['items'] ?? null) === true && $definition['items'] !== [];
	}//end hasItems()

	/**
	 * Bring the plan's items in through the case layer as the fragment's app.
	 *
	 * @param string               $app  The app that ships the fragment; the plan is written as that app.
	 * @param string               $key  The plan's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled plan fields.
	 *
	 * @return array{error: string, entry: array{object: string, created: array<int, string>}|null} The entry, or why not.
	 *
	 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$objectUuid = (string) $data['object'];
		$object     = $this->container->get(PartyService::class)->findUnscoped(uuid: $objectUuid);
		if ($object === null) {
			return ['error' => 'no object "'.$objectUuid.'" to put the plan on', 'entry' => null];
		}

		try {
			$result = $this->container->get(CasePlanService::class)->ensureItems(
				objectUuid: $objectUuid,
				registerId: (int) $object->getRegister(),
				schemaId: (int) $object->getSchema(),
				definition: (array) $data['definition'],
				history: (array) ($data['history'] ?? []),
				app: $app
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['object' => $objectUuid, 'created' => array_values(array_map('strval', $result['created']))]];
	}//end write()

	/**
	 * Whether none of the items the set created are there any more.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			return $this->createdItems(entry: $entry) === [] && (array) ($entry['created'] ?? []) !== [];
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Delete the items the set created, and the tasks they were realised by;
	 * items the plan held before stay. A task an item became when a cascade
	 * activated it goes with the item, so a refresh that puts the item back to
	 * `available` leaves no task of it in the case's list.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when they were already gone) or `failed`.
	 *
	 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$mapper = $this->container->get(CaseItemMapper::class);
			foreach ($this->createdItems(entry: $entry) as $item) {
				$this->deleteRealisationTask(item: $item);
				$mapper->delete($item);
			}
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * Delete the task an item was realised by, when it is one and still there.
	 *
	 * @param CaseItem $item The plan item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-case-plans/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	private function deleteRealisationTask(CaseItem $item): void {
		$uuid = trim((string) $item->getRealisationUuid());
		if ($item->getRealisationKind() !== CaseItem::REALISATION_TASK || $uuid === '') {
			return;
		}

		$tasks = $this->container->get(TaskMapper::class);
		try {
			$tasks->delete($tasks->findByUuid(uuid: $uuid));
		} catch (DoesNotExistException) {
			return;
		}
	}//end deleteRealisationTask()

	/**
	 * The object's plan items the set created, children before their parents.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return array<int, CaseItem>
	 */
	private function createdItems(array $entry): array {
		$keys  = array_map('strval', (array) ($entry['created'] ?? []));
		$items = array_filter(
			$this->container->get(CaseItemMapper::class)->findByObject(objectUuid: (string) $entry['object']),
			static fn (CaseItem $item): bool => in_array((string) $item->getItemKey(), $keys, true)
		);

		return array_reverse(array_values($items));
	}//end createdItems()
}//end class
