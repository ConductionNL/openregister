<?php

/**
 * Reads the seeded `working-calendar` and `escalation-ladder` objects.
 *
 * Both are DATA in the `flow-timers` register (ADR-001, ADR-031): an
 * administrator edits a ladder or adds an organisation's calendar as an
 * object, and this store reads them back by slug. When the register has not
 * been seeded yet (a fresh install between migration and repair step) the
 * shipped descriptor is read from disk for the SAME definitions, so the
 * defaults resolve identically either way. There is no third source and no
 * hard-coded fallback: an unknown slug is unknown.
 *
 * Loaded once per instance and memoised; the sweep resets it per pass.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Repair\SeedFlowTimerRegister;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Slug-keyed access to the seeded calendar and ladder definitions.
 */
class FlowTimerDefinitionStore {

	/**
	 * The register the definitions live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'flow-timers';

	/**
	 * The two schemas.
	 */
	public const SCHEMA_CALENDAR = 'working-calendar';

	public const SCHEMA_LADDER = 'escalation-ladder';

	/**
	 * Definitions per schema, keyed by slug; null until loaded.
	 *
	 * @var array<string, array<string, array<string, mixed>>|null>
	 */
	private array $cache = [
		self::SCHEMA_CALENDAR => null,
		self::SCHEMA_LADDER => null,
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects Reads the seeded objects.
	 * @param IAppManager $appManager Locates the shipped descriptor.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Every working-calendar definition, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> The definitions.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function calendars(): array {
		return $this->definitions(schema: self::SCHEMA_CALENDAR);
	}//end calendars()

	/**
	 * Every escalation-ladder definition, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> The definitions.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function ladders(): array {
		return $this->definitions(schema: self::SCHEMA_LADDER);
	}//end ladders()

	/**
	 * Forget the memoised definitions (called once per sweep pass).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function reset(): void {
		$this->cache = [
			self::SCHEMA_CALENDAR => null,
			self::SCHEMA_LADDER => null,
		];
	}//end reset()

	/**
	 * Load one schema's definitions: the register's objects, else the shipped descriptor.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>> Definitions keyed by slug.
	 */
	private function definitions(string $schema): array {
		if ($this->cache[$schema] !== null) {
			return $this->cache[$schema];
		}

		$loaded = $this->fromRegister(schema: $schema);
		if ($loaded === []) {
			$loaded = $this->fromDescriptor(schema: $schema);
		}

		$this->cache[$schema] = $loaded;

		return $loaded;
	}//end definitions()

	/**
	 * The seeded objects of a schema, as stored.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>> Definitions keyed by slug; empty when the register is absent.
	 */
	private function fromRegister(string $schema): array {
		try {
			$result = $this->objects->searchObjectsBySlug(
				registerSlug: self::REGISTER,
				schemaSlug: $schema,
				filters: [],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $failure) {
			$this->logger->debug(
				'[FlowTimerDefinitionStore] Register read failed, falling back to the shipped descriptor: ' . $failure->getMessage(),
				['schema' => $schema]
			);

			return [];
		}

		if (is_array($result) === false) {
			return [];
		}

		$definitions = [];
		foreach ($result as $entity) {
			$data = $entity->getObject();
			$slug = trim((string)($data['slug'] ?? ''));
			if ($slug !== '') {
				$definitions[$slug] = $data;
			}
		}

		return $definitions;
	}//end fromRegister()

	/**
	 * The shipped defaults, read from the descriptor the repair step imports.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>> Definitions keyed by slug.
	 */
	private function fromDescriptor(string $schema): array {
		try {
			$path = $this->appManager->getAppPath('openregister') . SeedFlowTimerRegister::REGISTER_PATH;
			$data = json_decode((string)file_get_contents($path), true);
		} catch (Throwable $failure) {
			$this->logger->warning('[FlowTimerDefinitionStore] Descriptor unreadable: ' . $failure->getMessage());

			return [];
		}

		$definitions = [];
		foreach (($data['components']['objects'] ?? []) as $object) {
			if (is_array($object) === false || (string)($object['@self']['schema'] ?? '') !== $schema) {
				continue;
			}

			$slug = trim((string)($object['slug'] ?? ''));
			if ($slug === '') {
				continue;
			}

			unset($object['@self']);
			$definitions[$slug] = $object;
		}

		return $definitions;
	}//end fromDescriptor()
}//end class
