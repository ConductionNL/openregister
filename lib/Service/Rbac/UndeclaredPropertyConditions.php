<?php

/**
 * Conditions on a property the record's schema does not declare.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A condition on a property no column holds can never match a row on a list,
 * where it compiles to `1 = 0`. On a single record the same key reads as null
 * and so meets `null` or `$exists: false`. This refuses such a condition on the
 * record too, so both paths agree.
 *
 * Only a plain body key is judged, only when the record does not carry it, and
 * only when the record names a schema that can be read; otherwise nothing is
 * refused here.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class UndeclaredPropertyConditions {

	/**
	 * Declared property names per schema id; null when the schema could not be read.
	 *
	 * @var array<string, array<string, true>|null>
	 */
	private array $declared = [];

	/**
	 * Schema and key pairs already logged.
	 *
	 * @var array<string, true>
	 */
	private array $logged = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the schema mapper lazily.
	 * @param LoggerInterface    $logger    Logs a refused condition once per schema and key.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a clause names a body property the record's schema does not declare.
	 *
	 * @param array<int|string, mixed> $match  The clause.
	 * @param array<int|string, mixed> $object The record as a rule reads it, with `@self.schema`.
	 *
	 * @return bool True when a condition can never match a row of this schema.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function refuses(array $match, array $object): bool {
		$schemaId = $this->schemaOf(object: $object);
		if ($schemaId === '') {
			return false;
		}

		foreach (array_keys($match) as $key) {
			$root = explode('.', (string)$key)[0];
			if ($this->isJudged(root: $root, object: $object) === false) {
				continue;
			}

			$declared = $this->declaredProperties(schemaId: $schemaId);
			if ($declared === null) {
				return false;
			}

			if (isset($declared[$root]) === false) {
				$this->logOnce(schemaId: $schemaId, key: (string)$key);
				return true;
			}
		}

		return false;
	}//end refuses()

	/**
	 * Whether a key is a plain body key the record does not carry.
	 *
	 * @param string                   $root   The key's first path segment.
	 * @param array<int|string, mixed> $object The record.
	 *
	 * @return bool True when the schema has to answer for it.
	 */
	private function isJudged(string $root, array $object): bool {
		if ($root === '' || str_starts_with($root, '_') === true || str_starts_with($root, '@') === true || str_starts_with($root, '$') === true) {
			return false;
		}

		return array_key_exists($root, $object) === false;
	}//end isJudged()

	/**
	 * The property names a schema declares, or null when it cannot be read.
	 *
	 * @param string $schemaId The schema id.
	 *
	 * @return array<string, true>|null The names.
	 */
	private function declaredProperties(string $schemaId): ?array {
		if (array_key_exists($schemaId, $this->declared) === true) {
			return $this->declared[$schemaId];
		}

		try {
			$schema = $this->container->get(SchemaMapperInterface::class)->find($schemaId, [], false, false);
			$this->declared[$schemaId] = array_fill_keys(array_map('strval', array_keys($schema->getProperties() ?? [])), true);
		} catch (Throwable $e) {
			$this->declared[$schemaId] = null;
		}

		return $this->declared[$schemaId];
	}//end declaredProperties()

	/**
	 * The schema id a record's `@self` names, or an empty string.
	 *
	 * @param array<int|string, mixed> $object The record.
	 *
	 * @return string The schema id.
	 */
	private function schemaOf(array $object): string {
		$schema = ($object['@self']['schema'] ?? null);
		if (is_array($schema) === true) {
			$schema = ($schema['id'] ?? null);
		}

		if (is_scalar($schema) === true) {
			return (string)$schema;
		}

		return '';
	}//end schemaOf()

	/**
	 * Log a refused condition once per schema and key.
	 *
	 * @param string $schemaId The schema id.
	 * @param string $key      The condition's key.
	 *
	 * @return void
	 */
	private function logOnce(string $schemaId, string $key): void {
		if (isset($this->logged[$schemaId.'|'.$key]) === true) {
			return;
		}

		$this->logged[$schemaId.'|'.$key] = true;
		$this->logger->warning(
			message: '[ConditionMatcher] A rule names a property its schema does not declare; the condition matches no record',
			context: ['schema' => $schemaId, 'key' => $key]
		);
	}//end logOnce()
}//end class
