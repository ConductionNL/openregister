<?php

/**
 * Any schema's objects as a shareable configuration type — no per-app code.
 *
 * Most apps store their shareable configuration as OpenRegister objects: procest
 * case types, shillinq payroll packs, opencatalogi publication types. Rather than
 * each app shipping a near-identical {@see FlowShareableConfigType}, a schema
 * simply marks itself shareable (its `configuration.shareable`), and OpenRegister
 * registers one of these for it. So making an app's configuration shareable is
 * data, not code — and carries none of the cross-app namespace/autoload risk a
 * per-app class does.
 *
 * This is the same serialise/deserialise as the flow type, generalised: select
 * the objects of one register+schema, strip the instance-specific fields, and
 * write them back on install.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config\Types
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

namespace OCA\OpenRegister\Service\Config\Types;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * A shareable type bound to one register + schema, built from a schema marker.
 */
class GenericObjectShareableConfigType implements IShareableConfigType {

	/**
	 * Instance-specific fields never carried in a portable bundle.
	 *
	 * @var array<int, string>
	 */
	private const INSTANCE_FIELDS = ['id', 'uuid', '@self', 'owner', 'organisation', 'created', 'updated'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads and writes the objects.
	 * @param string $id The type id (e.g. `procest.casetype`).
	 * @param string $name The display name.
	 * @param string $topic The discovery topic.
	 * @param string $register The register slug the objects live in.
	 * @param string $schema The schema slug the objects are of.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly string $id,
		private readonly string $name,
		private readonly string $topic,
		private readonly string $register,
		private readonly string $schema,
	) {

	}//end __construct()

	/**
	 * The type id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return $this->id;
	}//end getId()

	/**
	 * The display name.
	 *
	 * @return string The name.
	 */
	public function getDisplayName(): string {
		return $this->name;
	}//end getDisplayName()

	/**
	 * The discovery topic.
	 *
	 * @return string The topic.
	 */
	public function getTopic(): string {
		return $this->topic;
	}//end getTopic()

	/**
	 * Package this schema's objects into a portable bundle.
	 *
	 * `$selection` may carry `{ids: [...]}` to share specific objects; absent
	 * that, the whole set of the register/schema is shared. Each object is
	 * reduced to its portable fields.
	 *
	 * RBAC and multitenancy are LEFT ON. Both were previously disabled here,
	 * and both call sites of `serialise()` — `FederatedConfigService::bundle()`
	 * and `::publish()` — are reached from an ordinary authenticated HTTP
	 * request (`POST /api/federated-config/bundle`, `#[NoAdminRequired]`).
	 * That made this method a read of every organisation's objects in the
	 * register/schema, returned verbatim to any signed-in user. `_rbac: false`
	 * is an ENGINE escape hatch for paths that run with no session; a request
	 * reaches this one, so it must be scoped like any other read.
	 *
	 * @param array $selection `{ids?: [...]}`.
	 *
	 * @return array `{type, version, register, schema, objects: [...]}`.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function serialise(array $selection): array {
		$wanted = array_map('strval', (array)($selection['ids'] ?? []));

		$objects = [];
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => ['register' => $this->register, 'schema' => $this->schema]],
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			$found = [];
		}

		foreach ($found as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ($wanted !== [] && in_array((string)$object->getUuid(), $wanted, true) === false) {
				continue;
			}

			$objects[] = $this->portable(data: $object->getObject());
		}

		return [
			'type' => $this->id,
			'version' => '1.0',
			'register' => $this->register,
			'schema' => $this->schema,
			'objects' => $objects,
		];

	}//end serialise()

	/**
	 * Install a bundle's objects into this instance.
	 *
	 * @param array $bundle A bundle produced by this type.
	 *
	 * @return array `{installed: [uuid, ...]}`.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function deserialise(array $bundle): array {
		$installed = [];
		foreach ((array)($bundle['objects'] ?? []) as $object) {
			if (is_array($object) === false) {
				continue;
			}

			$saved = $this->objectService->saveObject(
				object: $this->portable(data: $object),
				register: $this->register,
				schema: $this->schema
			);

			$installed[] = (string)$saved->getUuid();
		}

		return ['installed' => $installed];
	}//end deserialise()

	/**
	 * Strip the instance-specific fields from an object's data.
	 *
	 * @param array $data The object data.
	 *
	 * @return array The portable subset.
	 */
	private function portable(array $data): array {
		foreach (self::INSTANCE_FIELDS as $field) {
			unset($data[$field]);
		}

		return $data;
	}//end portable()
}//end class
