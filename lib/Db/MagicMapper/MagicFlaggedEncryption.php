<?php

/**
 * MagicFlaggedEncryption: encrypted properties reach a magic table only as envelopes.
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
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Encrypt the flagged properties of what is about to be written.
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
 */
final class MagicFlaggedEncryption {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the encryption handler.
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
	 */
	public function __construct(private readonly ContainerInterface $container) {
	}//end __construct()

	/**
	 * Replace every `x-openregister-encrypted` property value with its envelope.
	 *
	 * SaveObject encrypts on the single-object path, but the table is the one
	 * place every write passes through. Encrypting here as well means no path
	 * (bulk, import, a service writing an entity back) can put plaintext in an
	 * encrypted column. FieldEncryptionHandler::encryptProperties() skips a value
	 * that is already an envelope, so the second pass never double-encrypts.
	 *
	 * Fails closed: if the schema has encrypted properties and the handler
	 * cannot be resolved, the write is refused rather than stored in the clear.
	 *
	 * @param array<string, mixed> $data   Property values, without `@self`.
	 * @param Schema               $schema The schema being written to.
	 *
	 * @return array<string, mixed> The data with encrypted properties as envelopes.
	 *
	 * @throws RuntimeException When the schema needs encryption and no handler is available.
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
	 */
	public function forObject(array $data, Schema $schema): array {
		if ($schema->hasEncryptedProperties() === false) {
			return $data;
		}

		return $this->handler(schema: $schema)->encryptProperties(data: $data, schema: $schema);
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
	 * @return array<int|string, mixed> The rows with encrypted properties as envelopes.
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
	 */
	public function forRows(array $objects, Schema $schema): array {
		if ($schema->hasEncryptedProperties() === false) {
			return $objects;
		}

		foreach ($objects as $key => $object) {
			if (is_array($object) === true) {
				$objects[$key] = $this->forRow(row: $object, schema: $schema);
			}
		}

		return $objects;
	}//end forRows()

	/**
	 * Encrypt one bulk row.
	 *
	 * @param array  $row    The row.
	 * @param Schema $schema The schema being written to.
	 *
	 * @return array The row with encrypted properties as envelopes.
	 */
	private function forRow(array $row, Schema $schema): array {
		if (isset($row['object']) === true && is_array($row['object']) === true) {
			$row['object'] = $this->forObject(data: $row['object'], schema: $schema);
			return $row;
		}

		return $this->forObject(data: $row, schema: $schema);
	}//end forRow()

	/**
	 * The encryption handler; without one the write is refused.
	 *
	 * @param Schema $schema The schema being written to.
	 *
	 * @return FieldEncryptionHandler
	 *
	 * @throws RuntimeException When no handler is available.
	 */
	private function handler(Schema $schema): FieldEncryptionHandler {
		$handler = $this->container->get(FieldEncryptionHandler::class);
		if ($handler instanceof FieldEncryptionHandler === false) {
			throw new RuntimeException(
				'Schema "' . ($schema->getSlug() ?? (string) $schema->getId())
				. '" has encrypted properties but no FieldEncryptionHandler is available; refusing to store them in the clear.'
			);
		}

		return $handler;
	}//end handler()
}//end class
