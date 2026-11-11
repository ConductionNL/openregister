<?php

/**
 * Turns schema markers into shareable configuration types.
 *
 * A schema marks itself shareable by setting `shareable` in its `configuration`
 * (either `true`, or an object refining the `id`, `topic` and `name`). This
 * scanner finds those schemas, works out which registers hold them, and produces
 * one {@see GenericObjectShareableConfigType} per register+schema pair — so an
 * app makes its configuration shareable with a data flag on its schema, never a
 * line of per-app PHP.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\Config\Types\GenericObjectShareableConfigType;
use OCA\OpenRegister\Service\Config\Types\ShareableTypeIdentity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Discovers shareable-marked schemas and builds a type for each.
 */
class SchemaShareableConfigScanner implements SchemaShareableConfigScannerInterface {
	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog Enumerates schemas and their markers, and the registers a schema belongs to.
	 * @param ObjectOperationsInterface $objectService Handed to each generated type.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The shareable types every marked schema declares.
	 *
	 * @return array<int, GenericObjectShareableConfigType> The generated types.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function scan(): array {
		try {
			$schemas = $this->catalog->schemas()->findAll();
			$registers = $this->catalog->registers()->findAll();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SchemaShareableConfigScanner] Could not scan for shareable schemas: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		return $this->typesFor(schemas: $schemas, registers: $registers);
	}//end scan()

	/**
	 * The shareable types of every marked schema, schema by schema.
	 *
	 * @param array<int, object> $schemas   Every schema.
	 * @param array<int, object> $registers Every register.
	 *
	 * @return array<int, GenericObjectShareableConfigType> The generated types.
	 */
	private function typesFor(array $schemas, array $registers): array {
		$types = [];
		foreach ($schemas as $schema) {
			$types = array_merge($types, $this->typesForSchema(schema: $schema, registers: $registers));
		}

		return $types;
	}//end typesFor()

	/**
	 * The shareable types of one schema: none when it is not marked, else one per
	 * register holding it (each register+schema pair is its own shareable set).
	 *
	 * @param object             $schema    The schema.
	 * @param array<int, object> $registers Every register.
	 *
	 * @return array<int, GenericObjectShareableConfigType> The generated types, in register order.
	 */
	private function typesForSchema(object $schema, array $registers): array {
		if ($this->markerOf(configuration: (array)($schema->getConfiguration() ?? [])) === null) {
			return [];
		}

		$types = [];
		foreach ($registers as $register) {
			if ($this->registerHasSchema(register: $register, schemaId: (int)$schema->getId()) === true) {
				$types[] = $this->typeFor(schema: $schema, register: $register);
			}
		}

		return $types;
	}//end typesForSchema()

	/**
	 * The shareable type of one marked schema in one register holding it.
	 *
	 * @param object $schema   The marked schema.
	 * @param object $register A register holding it.
	 *
	 * @return GenericObjectShareableConfigType The generated type.
	 */
	private function typeFor(object $schema, object $register): GenericObjectShareableConfigType {
		return new GenericObjectShareableConfigType(
			objectService: $this->objectService,
			identity: $this->identityFor(schema: $schema, register: $register),
			register: (string)$register->getSlug(),
			schema: (string)$schema->getSlug()
		);
	}//end typeFor()

	/**
	 * The identity of a register+schema shareable set: the marker's `id` / `topic` /
	 * `name` where it refines them, else derived from the slugs and titles.
	 *
	 * @param object $schema   The marked schema.
	 * @param object $register A register holding it.
	 *
	 * @return ShareableTypeIdentity The identity.
	 */
	private function identityFor(object $schema, object $register): ShareableTypeIdentity {
		$marker        = $this->markerOf(configuration: (array)($schema->getConfiguration() ?? []));
		$schemaSlug    = (string)$schema->getSlug();
		$schemaTitle   = (string)$schema->getTitle();
		$registerSlug  = (string)$register->getSlug();
		$registerTitle = (string)$register->getTitle();

		$id    = (string)($marker['id'] ?? ($registerSlug . '.' . $schemaSlug));
		$topic = (string)($marker['topic'] ?? ($registerSlug . '-' . $schemaSlug));
		$name  = (string)($marker['name'] ?? sprintf('%s (%s)', $schemaTitle, $registerTitle));

		return new ShareableTypeIdentity(id: $id, name: $name, topic: $topic);
	}//end identityFor()

	/**
	 * The shareable annotation key on a schema's configuration.
	 *
	 * Follows the `x-openregister-*` vendor-extension convention (sibling of
	 * `x-openregister-lifecycle`, `-mcp`, …). The key must also be declared in
	 * {@see Schema::ANNOTATION_VOCABULARY}, otherwise setConfiguration() drops
	 * it before it ever reaches this scanner.
	 *
	 * @var string
	 */
	private const MARKER_KEY = 'x-openregister-shareable';

	/**
	 * The shareable marker on a schema's configuration, normalised, or null.
	 *
	 * Accepts `x-openregister-shareable: true` (all defaults derived) or an
	 * object refining `id` / `topic` / `name`. Anything falsy is "not
	 * shareable".
	 *
	 * @param array $configuration The schema configuration.
	 *
	 * @return array<string, mixed>|null The marker, or null when not shareable.
	 */
	private function markerOf(array $configuration): ?array {
		$marker = ($configuration[self::MARKER_KEY] ?? null);
		if ($marker === null || $marker === false) {
			return null;
		}

		if (is_array($marker) === true) {
			return $marker;
		}

		// A truthy scalar (e.g. `true`) means "shareable with derived defaults".
		return [];
	}//end markerOf()

	/**
	 * Whether a register includes a schema.
	 *
	 * @param object $register The register.
	 * @param int $schemaId The schema id.
	 *
	 * @return boolean Whether the register holds the schema.
	 */
	private function registerHasSchema(object $register, int $schemaId): bool {
		foreach ((array)($register->getSchemas() ?? []) as $entry) {
			if ((int)$entry === $schemaId) {
				return true;
			}
		}

		return false;
	}//end registerHasSchema()
}//end class
