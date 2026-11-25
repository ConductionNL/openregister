<?php

/**
 * Reads the configuration an organisation owns as a promotion package.
 *
 * The package holds the organisation's schemas, mappings, sources, webhooks and
 * endpoints, each in its portable form: the entity's own serialisation without
 * the fields that belong to one instance (id, organisation, configuration
 * membership, timestamps). Secrets never travel: the serialisations already
 * redact them (a source says only whether authentication is configured, a
 * webhook's secret reads `***`), and a redacted value is dropped rather than
 * written into the target as if it were the secret.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
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
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use JsonSerializable;
use OCA\OpenRegister\Db\EndpointMapperInterface;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * The configuration components one organisation owns, ready to travel.
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */
class PromotionPackageReader {

	/**
	 * The fields of a serialised component that belong to one instance and never travel.
	 *
	 * @var array<int, string>
	 */
	private const INSTANCE_FIELDS = ['id', 'organisation', 'configurations', 'created', 'updated', 'owner'];

	/**
	 * The value a serialisation writes in place of a secret.
	 *
	 * @var string
	 */
	private const REDACTED = '***';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface   $schemas   The schemas.
	 * @param MappingMapperInterface  $mappings  The mappings.
	 * @param SourceMapperInterface   $sources   The sources.
	 * @param WebhookMapperInterface  $webhooks  The webhooks.
	 * @param EndpointMapperInterface $endpoints The endpoints.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly MappingMapperInterface $mappings,
		private readonly SourceMapperInterface $sources,
		private readonly WebhookMapperInterface $webhooks,
		private readonly EndpointMapperInterface $endpoints,
	) {
	}//end __construct()

	/**
	 * The components the organisation owns, schemas first.
	 *
	 * Every list is narrowed to rows whose organisation IS the given one, also
	 * where the mapper already filtered: a mapper that lets instance-wide rows
	 * (organisation null) through would otherwise promote configuration that
	 * belongs to nobody in particular into the target.
	 *
	 * @param string $organisationUuid The source organisation.
	 *
	 * @return PromotionComponent[] The components.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public function read(string $organisationUuid): array {
		$filter = ['organisation' => $organisationUuid];
		$rows   = [
			'schema' => $this->schemas->findAll(filters: $filter, _rbac: false, _multitenancy: false),
			'mapping' => $this->mappings->findAll(),
			'source' => $this->sources->findAll(filters: $filter),
			'webhook' => $this->webhooks->findAll(query: new ListQuery(limit: ListQuery::UNBOUNDED, filters: $filter)),
			'endpoint' => $this->endpoints->findAll(),
		];

		$components = [];
		foreach ($rows as $kind => $entities) {
			foreach ($entities as $entity) {
				$component = $this->componentOf(kind: $kind, entity: $entity, organisationUuid: $organisationUuid);
				if ($component !== null) {
					$components[] = $component;
				}
			}
		}

		return $components;
	}//end read()

	/**
	 * One entity as a component, or null when another organisation owns it.
	 *
	 * @param string $kind             The component kind.
	 * @param mixed  $entity           The entity.
	 * @param string $organisationUuid The source organisation.
	 *
	 * @return PromotionComponent|null The component.
	 */
	private function componentOf(string $kind, mixed $entity, string $organisationUuid): ?PromotionComponent {
		if ($entity instanceof JsonSerializable === false) {
			return null;
		}

		$serialised = (array) $entity->jsonSerialize();
		if (($serialised['organisation'] ?? null) !== $organisationUuid) {
			return null;
		}

		$uuid = $serialised['uuid'] ?? null;
		if (is_string($uuid) === false || $uuid === '') {
			$uuid = null;
		}

		return new PromotionComponent(
			kind: $kind,
			slug: self::slugOf(serialised: $serialised, kind: $kind),
			uuid: $uuid,
			definition: self::portable(serialised: $serialised)
		);
	}//end componentOf()

	/**
	 * A serialised component without its instance fields and redacted secrets.
	 *
	 * @param array<string, mixed> $serialised The serialisation.
	 *
	 * @return array<string, mixed> The portable definition.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public static function portable(array $serialised): array {
		foreach (self::INSTANCE_FIELDS as $field) {
			unset($serialised[$field]);
		}

		return self::withoutRedacted(values: $serialised);
	}//end portable()

	/**
	 * The values without any redacted secret, at any depth.
	 *
	 * @param array<mixed> $values The values.
	 *
	 * @return array<mixed> The values that are not redacted.
	 */
	private static function withoutRedacted(array $values): array {
		foreach ($values as $key => $value) {
			if ($value === self::REDACTED) {
				unset($values[$key]);
				continue;
			}

			if (is_array($value) === true) {
				$values[$key] = self::withoutRedacted(values: $value);
			}
		}

		return $values;
	}//end withoutRedacted()

	/**
	 * The name a component is drafted under: its slug, else its title or name, else its uuid.
	 *
	 * @param array<string, mixed> $serialised The serialisation.
	 * @param string               $kind       The component kind, the last fallback.
	 *
	 * @return string The slug.
	 */
	private static function slugOf(array $serialised, string $kind): string {
		foreach (['slug', 'reference', 'title', 'name', 'uuid', 'id'] as $field) {
			$value = $serialised[$field] ?? null;
			if ((is_string($value) === true || is_int($value) === true) && (string) $value !== '') {
				return self::keySafe(value: (string) $value);
			}
		}

		return $kind;
	}//end slugOf()

	/**
	 * A name usable as the last segment of a configuration key.
	 *
	 * @param string $value The name.
	 *
	 * @return string Lowercase, with every run of other characters a hyphen.
	 */
	private static function keySafe(string $value): string {
		$safe = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower($value)), '-');
		if ($safe === '') {
			return 'unnamed';
		}

		return $safe;
	}//end keySafe()
}//end class
