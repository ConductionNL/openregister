<?php

/**
 * OpenRegister LifecycleSeed
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds a lifecycle field on create from the parent object, when the schema asks for it.
 *
 * Rules (design: `initial` object-form + auto-seed on create):
 * - Empty-field-only: an explicitly provided value is NEVER overwritten.
 * - Fail-soft no-op (debug log) when the parent ref is empty, the parent
 *   cannot be loaded, or the parent's `initial.field` value is empty.
 * - The legacy literal-string `initial` form is NOT auto-seeded.
 * - Dispatches no `ObjectTransitionedEvent`: this is initialisation.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleSeed {

	/**
	 * @param MagicObjectFinderInterface $mapper Loads the parent object.
	 * @param LoggerInterface      $logger Records why a seed did nothing.
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The data, with the lifecycle field seeded when applicable.
	 *
	 * @param Schema $schema The schema whose annotation drives the seed.
	 * @param array  $data   The incoming object data (create payload).
	 *
	 * @return array The data.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function apply(Schema $schema, array $data): array {
		$seed = self::specOf(schema: $schema);
		if ($seed === null || self::isFilled(value: ($data[$seed['field']] ?? null)) === true) {
			return $data;
		}

		$value = $this->parentValue(seed: $seed, parentRef: (string)($data[$seed['from']] ?? ''));
		if ($value !== null) {
			$data[$seed['field']] = $value;
		}

		return $data;
	}//end apply()

	/**
	 * The object form of the annotation, or null when there is nothing to seed.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{from: string, parentPath: string, field: string}|null The seed.
	 */
	private static function specOf(Schema $schema): ?array {
		$annotation = (($schema->getConfiguration() ?? [])['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false || is_array($annotation['initial'] ?? null) === false) {
			return null;
		}

		$seed = [
			'from' => (string)($annotation['initial']['from'] ?? ''),
			'parentPath' => (string)($annotation['initial']['field'] ?? ''),
			'field' => (string)($annotation['field'] ?? ($annotation['property'] ?? '')),
		];
		if (in_array('', $seed, true) === true) {
			return null;
		}

		return $seed;
	}//end specOf()

	/**
	 * Whether a field holds a value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool False for null and the empty string.
	 */
	private static function isFilled(mixed $value): bool {
		return $value !== null && $value !== '';
	}//end isFilled()

	/**
	 * The value the parent gives, or null when the seed is a no-op.
	 *
	 * @param array{from: string, parentPath: string, field: string} $seed      The seed.
	 * @param string                                                 $parentRef The parent reference.
	 *
	 * @return mixed The value.
	 */
	private function parentValue(array $seed, string $parentRef): mixed {
		if ($parentRef === '') {
			$this->noOp(reason: 'parent reference is empty', seed: $seed);
			return null;
		}

		$parent = $this->loadParent(parentRef: $parentRef, field: $seed['field']);
		$value = (($parent?->getObject() ?? [])[$seed['parentPath']] ?? null);
		if ($parent !== null && self::isFilled(value: $value) === false) {
			$this->noOp(reason: 'parent initial value is empty', seed: $seed);
			return null;
		}

		return $value;
	}//end parentValue()

	/**
	 * Record why a seed did nothing.
	 *
	 * @param string                                                 $reason Why.
	 * @param array{from: string, parentPath: string, field: string} $seed   The seed.
	 *
	 * @return void
	 */
	private function noOp(string $reason, array $seed): void {
		$this->logger->debug(message: '[SaveObject] Lifecycle seed no-op: ' . $reason, context: $seed);
	}//end noOp()

	/**
	 * Load the parent, or null when it cannot be loaded.
	 *
	 * @param string $parentRef The parent reference.
	 * @param string $field     The lifecycle field, for the log.
	 *
	 * @return ObjectEntity|null The parent.
	 */
	private function loadParent(string $parentRef, string $field): ?ObjectEntity {
		try {
			return $this->mapper->find(identifier: $parentRef);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[SaveObject] Lifecycle seed no-op: parent could not be loaded: ' . $e->getMessage(),
				context: ['field' => $field, 'parentRef' => $parentRef]
			);
			return null;
		}
	}//end loadParent()
}//end class
