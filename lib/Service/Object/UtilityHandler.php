<?php

/**
 * UtilityHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Handles utility operations for ObjectService.
 *
 * This handler provides common utility functions:
 * - UUID validation
 * - Entity normalization (ID/string to object)
 * - Array normalization
 * - URL separator detection
 * - Efficiency calculations
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class UtilityHandler {
	/**
	 * Constructor for UtilityHandler.
	 *
	 * @param RegisterMapperInterface $registerMapper Mapper for register entities.
	 * @param SchemaMapperInterface $schemaMapper Mapper for schema entities.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Check if a value is a valid UUID string.
	 *
	 * Validates UUID format using regex pattern.
	 *
	 * @param mixed $value The value to check.
	 *
	 * @return bool True if the value is a valid UUID string.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isUuid($value): bool {
		if (is_string($value) === false) {
			return false;
		}

		// A UUID, with or without dashes or a word prefix.
		if (preg_match(UuidPattern::ANY, $value) === 1) {
			return true;
		}

		return false;
	}//end isUuid()

	/**
	 * Normalize a value to an array.
	 *
	 * If the value is already an array, return it as-is.
	 * Otherwise, wrap it in an array.
	 *
	 * @param mixed $value The value to normalize.
	 *
	 * @return array The normalized array.
	 *
	 * @psalm-return   array
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function normalizeToArray($value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [$value];
	}//end normalizeToArray()

	/**
	 * Get the appropriate URL separator for pagination.
	 *
	 * Returns '?' if the URL doesn't have a query string yet,
	 * otherwise returns '&' to append parameters.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return string The separator ('?' or '&').
	 *
	 * @psalm-return   '&'|'?'
	 * @phpstan-return '&'|'?'
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getUrlSeparator(string $url): string {
		if (strpos($url, '?') === false) {
			return '?';
		}

		return '&';
	}//end getUrlSeparator()

	/**
	 * Normalize entity identifier to entity object.
	 *
	 * Converts string/int identifiers to actual entity objects
	 * by loading them from the appropriate mapper.
	 *
	 * @param mixed $entity The entity identifier (string/int) or object.
	 * @param string $type The entity type ('register' or 'schema').
	 *
	 * @return mixed The entity object.
	 *
	 * @psalm-return   mixed
	 * @phpstan-return mixed
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function normalizeEntity($entity, string $type) {
		if (is_string($entity) === true || is_int($entity) === true) {
			if ($type === 'register') {
				return $this->registerMapper->find($entity);
			}

			return $this->schemaMapper->find($entity);
		}

		return $entity;
	}//end normalizeEntity()

}//end class
