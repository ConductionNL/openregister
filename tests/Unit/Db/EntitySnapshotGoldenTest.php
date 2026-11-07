<?php

/**
 * Snapshot golden test for lib/Db entities.
 *
 * Each file in EntitySnapshots/ was written from the entity as it was before
 * its constructor, serialisation or hydrate moved out of the entity file. The
 * test rebuilds the same snapshot from the current code and requires it to be
 * identical: field types in registration order, the serialised form of an
 * empty, a filled and two hydrated instances, the fields each marks updated,
 * and which setters refuse a sample value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Rebuilds each entity snapshot and compares it with the stored one.
 */
class EntitySnapshotGoldenTest extends TestCase {

	/**
	 * The fixed moment every datetime sample uses.
	 */
	private const MOMENT = '2026-01-02T03:04:05+00:00';

	/**
	 * One case per stored snapshot.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function snapshots(): array {
		$cases = [];
		foreach (glob(__DIR__ . '/EntitySnapshots/*.php') as $file) {
			$short = basename($file, '.php');
			$cases[$short] = ['OCA\\OpenRegister\\Db\\' . str_replace('__', '\\', $short), $file];
		}

		return $cases;
	}//end snapshots()

	/**
	 * The current code yields the stored snapshot.
	 *
	 * @param string $class The entity class.
	 * @param string $file  The stored snapshot.
	 *
	 * @return void
	 */
	#[DataProvider('snapshots')]
	public function testSnapshotIsUnchanged(string $class, string $file): void {
		$this->assertSame(require $file, self::snapshot($class));
	}//end testSnapshotIsUnchanged()

	/**
	 * The snapshot of one entity class.
	 *
	 * @param string $class The entity class.
	 *
	 * @return array<string, mixed>
	 */
	public static function snapshot(string $class): array {
		$empty = new $class();
		$filled = new $class();
		$refused = [];
		foreach (array_keys($filled->getFieldTypes()) as $name) {
			$refused[$name] = self::trySet($filled, $name, self::sample($name, $filled->getFieldTypes()[$name]));
		}

		return [
			'fieldTypes' => $empty->getFieldTypes(),
			'empty' => self::form($empty),
			'filled' => self::form($filled),
			'filledUpdated' => array_keys($filled->getUpdatedFields()),
			'refused' => array_filter($refused),
			'hydrated' => self::hydrated($class, false),
			'hydratedBlank' => self::hydrated($class, true),
		];
	}//end snapshot()

	/**
	 * A hydrated instance, from samples or from blank values, with an unknown key.
	 *
	 * @param string $class The entity class.
	 * @param bool   $blank Whether to use [] for json fields and '' for the rest.
	 *
	 * @return array<string, mixed>|string
	 */
	private static function hydrated(string $class, bool $blank): array|string {
		$entity = new $class();
		if (method_exists($entity, 'hydrate') === false) {
			return 'no hydrate';
		}

		$row = ['notAField' => 'x'];
		foreach ($entity->getFieldTypes() as $name => $type) {
			$row[$name] = self::sample($name, $type);
			if ($blank === true) {
				$row[$name] = ($type === 'json') ? [] : '';
			}
		}

		try {
			$returned = $entity->hydrate($row);
		} catch (Throwable $e) {
			return 'throws ' . get_class($e);
		}

		return ['returnsSelf' => $returned === $entity, 'form' => self::form($entity), 'updated' => array_keys($entity->getUpdatedFields())];
	}//end hydrated()

	/**
	 * Call one setter; the refusal's class, or null when it was accepted.
	 *
	 * @param Entity $entity The entity.
	 * @param string $name   The field.
	 * @param mixed  $value  The value.
	 *
	 * @return string|null
	 */
	private static function trySet(Entity $entity, string $name, mixed $value): ?string {
		try {
			$entity->{'set' . ucfirst($name)}($value);
		} catch (Throwable $e) {
			return get_class($e);
		}

		return null;
	}//end trySet()

	/**
	 * A deterministic sample value for a field type.
	 *
	 * @param string $name The field.
	 * @param string $type The field type.
	 *
	 * @return mixed
	 */
	private static function sample(string $name, string $type): mixed {
		return match ($type) {
			'integer', 'int', 'bigint', 'smallint' => strlen($name),
			'float', 'decimal' => 1.5,
			'boolean', 'bool' => true,
			'json' => ['k' => $name],
			'datetime', 'date', 'time', 'datetimetz' => new DateTime(self::MOMENT),
			'datetime_immutable', 'date_immutable', 'datetimetz_immutable' => new DateTimeImmutable(self::MOMENT),
			default => $name . '-v',
		};
	}//end sample()

	/**
	 * The serialised form of an entity, with objects made comparable.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return mixed
	 */
	private static function form(Entity $entity): mixed {
		if ($entity instanceof JsonSerializable === false) {
			return 'not serialisable';
		}

		try {
			return self::plain($entity->jsonSerialize());
		} catch (Throwable $e) {
			return 'throws ' . get_class($e);
		}
	}//end form()

	/**
	 * A value with every object replaced by a comparable description.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function plain(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(static fn (mixed $item): mixed => self::plain($item), $value);
		}

		if ($value instanceof DateTimeInterface) {
			return get_class($value) . '@' . $value->format('c');
		}

		if (is_object($value) === true) {
			return get_class($value) . ':' . json_encode($value);
		}

		return $value;
	}//end plain()
}//end class
