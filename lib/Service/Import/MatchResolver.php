<?php

/**
 * Resolves which existing objects a source row matches.
 *
 * The conflict policy is only as honest as the key it is applied against, so
 * the key is declared rather than inferred: an import names the properties a
 * row is matched on, and this resolver returns every object that matches all
 * of them. It returns a list rather than one object on purpose, because the
 * count is what the policy decides on and a count above one is a refusal
 * (D-3), never a choice.
 *
 * The declared key `id` (or `@self.id`) matches on the object's own
 * identifier, which is the match the import path used before this change.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds the objects a mapped row matches, by the import's declared key.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class MatchResolver {

	/**
	 * The most candidates one row's lookup returns. Three is enough to tell
	 * "none", "one" and "more than one" apart, and the refusal names what it
	 * found rather than every last duplicate.
	 *
	 * @var int
	 */
	private const CANDIDATE_CAP = 3;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The object read path.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Normalise a declared match key into a list of property names.
	 *
	 * @param array<int, string>|string|null $matchKey The declared key.
	 *
	 * @return array<int, string> The property names, possibly empty.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function normaliseKey(array|string|null $matchKey): array {
		if ($matchKey === null) {
			return [];
		}

		if (is_string($matchKey) === true) {
			$matchKey = explode(',', $matchKey);
		}

		$normalised = [];
		foreach ($matchKey as $part) {
			$part = trim((string)$part);
			if ($part !== '') {
				$normalised[] = $part;
			}
		}

		return array_values(array_unique($normalised));
	}//end normaliseKey()

	/**
	 * The uuids of every object the row matches.
	 *
	 * An empty key, or a row that carries no value for one of the key's
	 * properties, matches nothing: a row with a blank key is a new record,
	 * not a match against every object whose property is also blank.
	 *
	 * @param array<string, mixed> $object The mapped object, in the shape the save path takes.
	 * @param array<int, string> $matchKey The declared key's property names.
	 * @param Register $register The target register.
	 * @param Schema $schema The target schema.
	 *
	 * @return array<int, string> The uuids the key hit, capped.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function resolve(array $object, array $matchKey, Register $register, Schema $schema): array {
		if ($matchKey === []) {
			return [];
		}

		$filters = [
			'register' => $register->getId(),
			'schema' => $schema->getId(),
		];

		foreach ($matchKey as $property) {
			$value = $this->readValue(object: $object, property: $property);

			if ($value === null || $value === '') {
				return [];
			}

			if ($property === 'id' || $property === '@self.id') {
				$filters['id'] = $value;
				continue;
			}

			$filters[$property] = $value;
		}

		try {
			$found = $this->objectService->findAll(
				config: [
					'filters' => $filters,
					'limit' => self::CANDIDATE_CAP,
				]
			);
		} catch (Throwable $exception) {
			// A lookup that cannot run is not "no match": treating it as one
			// would create a duplicate of every object in the register. It is
			// reported to the caller as a failed match so the row is refused.
			$this->logger->warning(
				message: '[MatchResolver] Match lookup failed: '.$exception->getMessage(),
				context: ['filters' => array_keys($filters)]
			);

			throw new MatchLookupFailedException(
				'The match key could not be resolved: '.$exception->getMessage(),
				0,
				$exception
			);
		}//end try

		$uuids = [];
		foreach ($found as $entry) {
			$uuid = $this->readUuid(entry: $entry);
			if ($uuid !== null) {
				$uuids[] = $uuid;
			}
		}

		return array_values(array_unique($uuids));
	}//end resolve()

	/**
	 * Read one property off a mapped object, understanding the `@self` box.
	 *
	 * @param array<string, mixed> $object The mapped object.
	 * @param string $property The property name.
	 *
	 * @return string|null The value as a string, or null when absent or not scalar.
	 */
	private function readValue(array $object, string $property): ?string {
		if (str_starts_with($property, '@self.') === true) {
			$selfProperty = substr($property, 6);
			$value = ($object['@self'][$selfProperty] ?? null);
		} elseif ($property === 'id') {
			$value = ($object['@self']['id'] ?? $object['id'] ?? null);
		} else {
			$value = ($object[$property] ?? null);
		}

		if (is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end readValue()

	/**
	 * Read the uuid off a rendered object.
	 *
	 * @param mixed $entry One entry as findAll returned it.
	 *
	 * @return string|null The uuid, or null when the entry carries none.
	 */
	private function readUuid(mixed $entry): ?string {
		if (is_array($entry) === false) {
			if (is_object($entry) === true && method_exists($entry, 'getUuid') === true) {
				return $entry->getUuid();
			}

			return null;
		}

		$uuid = ($entry['@self']['id'] ?? $entry['uuid'] ?? $entry['id'] ?? null);

		if (is_scalar($uuid) === false) {
			return null;
		}

		return (string)$uuid;
	}//end readUuid()
}//end class
