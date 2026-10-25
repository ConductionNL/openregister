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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
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
	 * @param PropertyRbacHandler $properties  The property read rule.
	 * @param IUserSession        $userSession The reader.
	 * @param LoggerInterface     $logger      The logger.
	 * @param RevealCollector|null $reveals    Records a reveal; absent, nothing is recorded.
	 */
	public function __construct(
		private readonly ExplicitReveal $reveal,
		private readonly PropertyRbacHandler $properties,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?RevealCollector $reveals = null,
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
		$names = ExplicitReveal::explicitProperties(schema: $schema);
		if ($names === [] || $this->reveal->isTrusted() === true) {
			return [$object, []];
		}

		$given = array_filter($objectIds, static fn (string $id): bool => self::isUuid(value: $id));
		$objectIds = array_values(array_merge($given, $this->idsOf(object: $object)));
		$masked = [];
		foreach ($names as $name) {
			if ($this->hasValue(object: $object, property: $name) === false) {
				continue;
			}

			if ($this->mayReveal(schema: $schema, object: $object, objectIds: $objectIds, property: $name) === true) {
				$this->record(schema: $schema, objectIds: $objectIds, property: $name);
				continue;
			}

			$object[$name] = ExplicitReveal::mask(value: $object[$name]);
			$masked[] = $name;
		}

		return [$object, $masked];
	}//end maskObject()

	/**
	 * Whether the property holds a value to mask.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param string               $property The property.
	 *
	 * @return bool
	 */
	private function hasValue(array $object, string $property): bool {
		return array_key_exists($property, $object) === true && $object[$property] !== null && $object[$property] !== '';
	}//end hasValue()

	/**
	 * Whether this read was granted the property and the reader may read it.
	 *
	 * @param Schema|null          $schema    The schema.
	 * @param array<string, mixed> $object    The object.
	 * @param array<int, string>   $objectIds Its ids.
	 * @param string               $property  The property.
	 *
	 * @return bool
	 */
	private function mayReveal(?Schema $schema, array $object, array $objectIds, string $property): bool {
		if ($schema === null || $this->reveal->isGranted(objectIds: $objectIds, property: $property) === false) {
			return false;
		}

		return $this->properties->canReadProperty(schema: $schema, property: $property, object: $object);
	}//end mayReveal()

	/**
	 * Record a granted reveal of an audited property. Never blocks the read.
	 *
	 * @param Schema|null        $schema    The schema.
	 * @param array<int, string> $objectIds The object's ids, the first being its uuid.
	 * @param string             $property  The property.
	 *
	 * @return void
	 */
	private function record(?Schema $schema, array $objectIds, string $property): void {
		$rules = ($schema?->getProperties()[$property]['authorization'] ?? null);
		if ($this->reveals === null || is_array($rules) === false || $this->reveals->isAudited(propertyRules: $rules) === false) {
			return;
		}

		try {
			$this->reveals->record(
				userId: (string)$this->userSession->getUser()?->getUID(),
				objectUuid: (string)($objectIds[0] ?? ''),
				property: $property,
				schemaId: $schema?->getId()
			);
		} catch (Throwable $e) {
			$this->logger->warning(
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
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
	}//end isUuid()
}//end class
