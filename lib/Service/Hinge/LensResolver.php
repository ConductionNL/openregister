<?php

/**
 * LensResolver — a property that shows a referenced record's field, live.
 *
 * Copying the besluit's date onto the bezwaar makes two dates that disagree the
 * moment one of them moves. A lens holds the path, not the value, and resolves
 * it on read (D-2). Where the reader may not see the referenced object, the
 * lens says withheld rather than nothing at all, because an empty date reads as
 * "there is no besluit" (D-3).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a schema's declared lenses onto an object's rendered data.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A lens reads through the object,
 *   schema and permission layers by definition.
 */
class LensResolver {

	/**
	 * The marker a lens renders as when the caller may not read through it.
	 *
	 * An empty value reads as "there is no besluit"; this reads as "you may not
	 * see it". The difference matters most exactly where the information is
	 * sensitive, so the two are never rendered the same way.
	 *
	 * @var array<string, mixed>
	 */
	public const WITHHELD = [
		'@withheld' => true,
		'reason' => 'access',
	];

	/**
	 * Wire the lookups a lens resolves through.
	 *
	 * @param MagicMapper       $magicMapper       Object storage, to read the referenced record.
	 * @param SchemaMapper      $schemaMapper      Schema lookup for the referenced record.
	 * @param PermissionHandler $permissionHandler The read check over the referenced record.
	 * @param IUserSession      $userSession       The caller, for that check.
	 * @param LoggerInterface   $logger            PSR logger for unresolvable lenses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly PermissionHandler $permissionHandler,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply a schema's lenses to one object's data.
	 *
	 * Returns the data unchanged when the schema declares no lens, which is the
	 * whole of the backwards-compatibility promise: a schema that says nothing
	 * about lenses behaves exactly as it did.
	 *
	 * @param Schema|null $schema The schema of the object being rendered.
	 * @param array       $data   The object's rendered data.
	 * @param bool        $_rbac  Whether the caller's access is being enforced.
	 *
	 * @return array The data, with every declared lens resolved.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function apply(?Schema $schema, array $data, bool $_rbac = true): array {
		if ($schema === null) {
			return $data;
		}

		$lenses = $schema->getLenses();
		if ($lenses === []) {
			return $data;
		}

		foreach ($lenses as $name => $lens) {
			$data[$name] = $this->resolveOne(lens: $lens, data: $data, _rbac: $_rbac);
		}

		return $data;
	}//end apply()

	/**
	 * The property names a schema declares as lenses.
	 *
	 * Written by nobody: a lens is resolved at read and refused on write, so
	 * the save path asks for this list to know what to refuse.
	 *
	 * @param Schema|null $schema The schema being written to.
	 *
	 * @return array<int, string> The lens property names.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public static function lensPropertyNames(?Schema $schema): array {
		if ($schema === null) {
			return [];
		}

		return array_keys($schema->getLenses());
	}//end lensPropertyNames()

	/**
	 * The lens properties a payload names, which is what a write is refused for.
	 *
	 * Presence is the test, not the value: a lens is stored nowhere, so there is
	 * no stored value a sent one could agree with. A client that read the object
	 * must drop its lens properties before sending it back.
	 *
	 * @param Schema|null $schema The schema being written to.
	 * @param array       $object The incoming payload.
	 *
	 * @return array<int, string> The lens properties the payload names, in declaration order.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public static function refusedLensWrites(?Schema $schema, array $object): array {
		$named = [];
		foreach (self::lensPropertyNames(schema: $schema) as $name) {
			if (array_key_exists($name, $object) === true) {
				$named[] = $name;
			}
		}

		return $named;
	}//end refusedLensWrites()

	/**
	 * Resolve one lens to a value, to the withheld marker, or to null.
	 *
	 * @param array $lens  The declaration: `through` and `property`.
	 * @param array $data  The object's own data, holding the reference.
	 * @param bool  $_rbac Whether the caller's access is being enforced.
	 *
	 * @return mixed The referenced value, the withheld marker, or null.
	 */
	private function resolveOne(array $lens, array $data, bool $_rbac): mixed {
		$reference = $this->referenceIdentifier(value: ($data[$lens['through']] ?? null));
		if ($reference === null) {
			return null;
		}

		try {
			// Read the referenced object WITHOUT the access filter, then decide.
			// A filtered read cannot tell "there is no besluit" from "you may not
			// see it", and rendering those two the same way is the defect D-3
			// exists to prevent. Nothing but the one declared property leaves this
			// method, and only after mayRead() says so.
			$referenced = $this->magicMapper->find(
				identifier: $reference,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				sprintf('[LensResolver] Referenced object "%s" could not be read: %s', $reference, $e->getMessage())
			);
			return null;
		}

		if (($referenced instanceof ObjectEntity) === false) {
			return null;
		}

		if ($this->mayRead(referenced: $referenced, _rbac: $_rbac) === false) {
			return self::WITHHELD;
		}

		return $this->readPath(data: ($referenced->getObject() ?? []), path: $lens['property']);
	}//end resolveOne()

	/**
	 * Whether the caller may read the referenced object.
	 *
	 * A referenced object whose schema cannot be resolved is treated as
	 * unreadable, not as readable: a lens that fails open is a disclosure.
	 *
	 * @param ObjectEntity $referenced The referenced object.
	 * @param bool         $_rbac      Whether the caller's access is being enforced.
	 *
	 * @return bool True when the caller may read it.
	 */
	private function mayRead(ObjectEntity $referenced, bool $_rbac): bool {
		if ($_rbac === false) {
			return true;
		}

		try {
			$schema = $this->schemaMapper->find(
				id: $referenced->getSchema(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				sprintf('[LensResolver] Schema of a referenced object could not be read: %s', $e->getMessage())
			);
			return false;
		}

		$userId = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
		}

		return $this->permissionHandler->hasPermission(
			schema: $schema,
			action: 'read',
			userId: $userId,
			objectOwner: $referenced->getOwner(),
			_rbac: true,
			object: $referenced
		);
	}//end mayRead()

	/**
	 * The uuid or id a reference property holds.
	 *
	 * A `$ref` property may hold a bare identifier, a uri ending in one, or an
	 * already-extended object carrying its own id.
	 *
	 * @param mixed $value The reference property's value.
	 *
	 * @return string|null The identifier, or null when there is none.
	 */
	private function referenceIdentifier(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			$segments = explode('/', rtrim($value, '/'));
			return end($segments);
		}

		if (is_int($value) === true) {
			return (string)$value;
		}

		if (is_array($value) === true) {
			$candidate = ($value['id'] ?? ($value['uuid'] ?? null));
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end referenceIdentifier()

	/**
	 * Read a dot path out of the referenced record's data.
	 *
	 * @param array  $data The referenced record's data.
	 * @param string $path The property to read, dot-separated for nesting.
	 *
	 * @return mixed The value, or null when the path leads nowhere.
	 */
	private function readPath(array $data, string $path): mixed {
		$cursor = $data;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end readPath()
}//end class
