<?php

/**
 * Masks explicit-reveal values on every route that returns them.
 *
 * The one place a rendered object, a GraphQL node, an access-link read, an
 * passes before it leaves: each explicit-reveal
 * property reads as its mask, unless the single-object read granted it
 * (`_reveal`) and the reader may read it, in which case the value stays and the
 * reveal is recorded. See {@see ExplicitReveal} for the rules.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\SchemaProperty;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCP\IUserSession;
use Throwable;

/**
 * Masks, reveals and records explicit-reveal properties.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
class ExplicitRevealMasker {

	/**
	 * Constructor.
	 *
	 * @param ExplicitReveal      $reveal      The request's grants.
	 * @param PropertyRbacHandlerInterface $properties  The property read rule.
	 * @param IUserSession        $userSession The reader.
	 * @param RevealRecording $recording The log line and the collector a reveal is recorded in.
	 */
	public function __construct(
		private readonly ExplicitReveal $reveal,
		private readonly PropertyRbacHandlerInterface $properties,
		private readonly IUserSession $userSession,
		private readonly RevealRecording $recording,
	) {
	}//end __construct()

	/**
	 * Mask an object's explicit-reveal properties, keeping the granted ones.
	 *
	 * @param Schema|null          $schema    The object's schema.
	 * @param array<string, mixed> $object    The object.
	 * @param array<int, string>   $objectIds The ids the object answers to (uuid, id, slug).
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>} The object and the masked property names.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function maskObject(?Schema $schema, array $object, array $objectIds = []): array {
		return $this->mask(run: new RevealMaskRun(schema: $schema, object: $object, objectIds: $objectIds));
	}//end maskObject()

	/**
	 * Mask the run's explicit-reveal properties, keeping the granted ones.
	 *
	 * @param RevealMaskRun $run The schema, the object and the ids it was given.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>} The object and the masked property names.
	 */
	private function mask(RevealMaskRun $run): array {
		$names = ExplicitReveal::explicitProperties(schema: $run->schema);
		if ($names === [] || $this->reveal->isTrusted() === true) {
			return [$run->object, []];
		}

		$given = array_filter($run->objectIds, static fn (string $id): bool => self::isUuid(value: $id));
		$run->objectIds = array_values(array_merge($given, $this->idsOf(object: $run->object)));
		$masked = [];
		foreach ($names as $name) {
			if ($this->hasValue(run: $run, property: $name) === false) {
				continue;
			}

			if ($this->mayReveal(run: $run, property: $name) === true) {
				$this->record(run: $run, property: $name);
				continue;
			}

			$run->object[$name] = ExplicitReveal::mask(value: $run->object[$name]);
			$masked[] = $name;
		}

		return [$run->object, $masked];
	}//end mask()

	/**
	 * Whether the property holds a value to mask.
	 *
	 * @param RevealMaskRun $run      The run, carrying the object.
	 * @param string        $property The property.
	 *
	 * @return bool
	 */
	private function hasValue(RevealMaskRun $run, string $property): bool {
		$object = $run->object;
		return array_key_exists($property, $object) === true && $object[$property] !== null && $object[$property] !== '';
	}//end hasValue()

	/**
	 * Whether this read was granted the property and the reader may read it.
	 *
	 * @param RevealMaskRun $run      The run: schema, object as masked so far, ids.
	 * @param string        $property The property.
	 *
	 * @return bool
	 */
	private function mayReveal(RevealMaskRun $run, string $property): bool {
		if ($run->schema === null || $this->reveal->isGranted(objectIds: $run->objectIds, property: $property) === false) {
			return false;
		}

		return $this->properties->canReadProperty(property: new SchemaProperty(schema: $run->schema, name: $property), request: new PropertyAccessRequest(object: $run->object));
	}//end mayReveal()

	/**
	 * Record a granted reveal of an audited property. Never blocks the read.
	 *
	 * @param RevealMaskRun $run      The run: the schema and the ids, the first being the uuid.
	 * @param string        $property The property.
	 *
	 * @return void
	 */
	private function record(RevealMaskRun $run, string $property): void {
		$schema = $run->schema;
		$rules = ($schema?->getProperties()[$property]['authorization'] ?? null);
		if ($this->recording->reveals === null || is_array($rules) === false || $this->recording->reveals->isAudited(propertyRules: $rules) === false) {
			return;
		}

		try {
			$this->recording->reveals->recordReveal(
				reveal: new PropertyReveal(
					userId: (string)$this->userSession->getUser()?->getUID(),
					objectUuid: (string)($run->objectIds[0] ?? ''),
					property: $property,
					schemaId: $schema?->getId()
				)
			);
		} catch (Throwable $e) {
			$this->recording->logger->warning(
				message: '[ExplicitRevealMasker] Could not record a reveal; the read is unaffected',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $property, 'error' => $e->getMessage()]
			);
		}
	}//end record()

	/**
	 * The uuids an object carries (`uuid`, `id`, `@self.id`): a grant matches a
	 * uuid only, never a numeric id or a slug another schema may share.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function idsOf(array $object): array {
		$ids = [];
		foreach ([$object['uuid'] ?? null, $object['id'] ?? null, $object['@self']['id'] ?? null] as $id) {
			if (is_string($id) === true && self::isUuid(value: $id) === true) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end idsOf()

	/**
	 * Whether a value is a uuid.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function isUuid(string $value): bool {
		return UuidFormat::isCanonical(value: $value);
	}//end isUuid()
}//end class
