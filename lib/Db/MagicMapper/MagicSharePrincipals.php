<?php

/**
 * MagicSharePrincipals: the derived share-principal lists reach a magic table only as derived.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriverInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Recompute `sharedUsers` and `sharedGroups` from `sharedWith` on every write
 * to a schema that declares all three.
 *
 * The RBAC predicates of a share match the two derived lists, so a stale or
 * client-supplied list is an access decision nobody made. The share endpoints
 * derive them, but the table is the one place every write passes through: the
 * generic object API, bulk, import and a service writing an entity back. Doing
 * it here means none of those can store a derived list that disagrees with the
 * share list. Recomputing is idempotent, so a write that was already derived
 * is stored unchanged.
 *
 * Only a schema declaring `sharedWith`, `sharedUsers` and `sharedGroups` is
 * touched; any other schema's data passes through as it came. Fails closed: if
 * such a schema is written and the deriver cannot be resolved, the write is
 * refused rather than stored with lists nobody recomputed.
 *
 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
 */
final class MagicSharePrincipals {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the share-principal deriver.
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
	 */
	public function __construct(private readonly ContainerInterface $container) {
	}//end __construct()

	/**
	 * Whether the schema carries a share list with its two derived lists.
	 *
	 * @param Schema $schema The schema being written to.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
	 */
	public static function declares(Schema $schema): bool {
		$properties = $schema->getProperties();

		return array_key_exists(SharePrincipalDeriverInterface::PROP_SHARED_WITH, $properties) === true
			&& array_key_exists(SharePrincipalDeriverInterface::PROP_SHARED_USERS, $properties) === true
			&& array_key_exists(SharePrincipalDeriverInterface::PROP_SHARED_GROUPS, $properties) === true;
	}//end declares()

	/**
	 * The object's property values with the derived lists recomputed.
	 *
	 * @param array<string, mixed> $data   Property values, without `@self`.
	 * @param Schema               $schema The schema being written to.
	 *
	 * @return array<string, mixed> The data, its derived lists recomputed when the schema declares them.
	 *
	 * @throws RuntimeException When the schema declares the lists and no deriver is available.
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
	 */
	public function forObject(array $data, Schema $schema): array {
		if (self::declares(schema: $schema) === false) {
			return $data;
		}

		return $this->deriver(schema: $schema)->apply(object: $data);
	}//end forObject()

	/**
	 * Apply forObject() to every row of a bulk write.
	 *
	 * A bulk row carries its properties either under `object` or at the top
	 * level beside `@self`, the two shapes MagicBulkHandler reads.
	 *
	 * @param array<int|string, mixed> $objects The rows.
	 * @param Schema                   $schema  The schema being written to.
	 *
	 * @return array<int|string, mixed> The rows, their derived lists recomputed when the schema declares them.
	 *
	 * @throws RuntimeException When the schema declares the lists and no deriver is available.
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
	 */
	public function forRows(array $objects, Schema $schema): array {
		if (self::declares(schema: $schema) === false) {
			return $objects;
		}

		$deriver = $this->deriver(schema: $schema);
		foreach ($objects as $key => $row) {
			if (is_array($row) === false) {
				continue;
			}

			if (isset($row['object']) === true && is_array($row['object']) === true) {
				$row['object'] = $deriver->apply(object: $row['object']);
				$objects[$key] = $row;
				continue;
			}

			$self = ($row['@self'] ?? null);
			unset($row['@self']);
			$row = $deriver->apply(object: $row);
			if ($self !== null) {
				$row['@self'] = $self;
			}

			$objects[$key] = $row;
		}

		return $objects;
	}//end forRows()

	/**
	 * The deriver; without one the write is refused.
	 *
	 * @param Schema $schema The schema being written to.
	 *
	 * @return SharePrincipalDeriverInterface
	 *
	 * @throws RuntimeException When no deriver is available.
	 */
	private function deriver(Schema $schema): SharePrincipalDeriverInterface {
		try {
			$deriver = $this->container->get(SharePrincipalDeriverInterface::class);
		} catch (Throwable $e) {
			$deriver = null;
		}

		if ($deriver instanceof SharePrincipalDeriverInterface === false) {
			throw new RuntimeException(
				'Schema "' . ($schema->getSlug() ?? (string) $schema->getId())
				. '" carries a share list but no share-principal deriver is available; refusing to store unchecked principal lists.'
			);
		}

		return $deriver;
	}//end deriver()
}//end class
