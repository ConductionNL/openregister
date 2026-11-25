<?php

/**
 * ExternalRegisterProvider — the `external-register` integration leaf: a
 * record of a sourced schema, shown on another object and keyed by one of
 * its properties.
 *
 * A placement names the sourced schema, the host property holding the key
 * and the fields to show, e.g. `{"schema": "bag-adres", "key":
 * "address.identifier", "display": ["straat", "huisnummer", "woonplaats"]}`.
 * The widget and tab surfaces are declared on the JS side (AD-6); this is the
 * registry entry and the read they call.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalRegisterLookupInterface;
use OCP\IL10N;

/**
 * The external-register leaf (ADR-019, group `data`).
 *
 * No app is required: the connector app and its source belong to the sourced
 * schema, and a missing one is an explained state of the read, not a reason
 * to hide the leaf. The record is never copied into the host object.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
class ExternalRegisterProvider extends AbstractIntegrationProvider {

	/**
	 * The leaf id.
	 *
	 * @var string
	 */
	public const ID = 'external-register';

	/**
	 * What a placement of this leaf declares.
	 *
	 * @var array<string, mixed>
	 */
	public const PLACEMENT_SCHEMA = [
		'type' => 'object',
		'required' => ['schema', 'key'],
		'additionalProperties' => false,
		'properties' => [
			'register' => [
				'type' => 'string',
				'description' => 'The register of the sourced schema; the host object\'s register when absent.',
			],
			'schema' => [
				'type' => 'string',
				'minLength' => 1,
				'description' => 'The sourced schema (x-openregister-object-source) whose record is shown.',
			],
			'key' => [
				'type' => 'string',
				'minLength' => 1,
				'description' => 'The host object\'s property holding the key, as a dot path.',
			],
			'display' => [
				'type' => 'array',
				'items' => ['type' => 'string', 'minLength' => 1],
				'description' => 'The record fields to show, in this order; all of them when absent.',
			],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ExternalRegisterLookupInterface $lookup Reads the record and names its state.
	 * @param IL10N                           $l10n   Localises the label.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function __construct(
		private readonly ExternalRegisterLookupInterface $lookup,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The leaf id.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function getLabel(): string {
		return $this->l10n->t('External register');
	}//end getLabel()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The MDI icon name.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function getIcon(): string {
		return 'DatabaseSearchOutline';
	}//end getIcon()

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null The group.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function getGroup(): ?string {
		return 'data';
	}//end getGroup()

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null Null: the sourced schema carries its own dependency.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * {@inheritDoc}
	 *
	 * @return string `external`: nothing is stored.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function getStorageStrategy(): string {
		return 'external';
	}//end getStorageStrategy()

	/**
	 * {@inheritDoc}
	 *
	 * @return bool Always true; an unavailable source is a state of the read.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * The record a placement shows on the host object, as a one-row list.
	 *
	 * Filters carry the placement: `schema`, `key` (the VALUE read from the
	 * host property), `register` (defaults to the host's), `_refresh`. The row
	 * is the lookup's answer, record or not, so the widget always has a state
	 * to explain; a schema the caller may not read gives no row.
	 *
	 * @param ObjectRef            $ref     The host object.
	 * @param array<string, mixed> $filters The placement and the key value.
	 *
	 * @return array<int, array<string, mixed>> The answer, or none.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md#requirement-an-external-register-record-renders-on-another-object
	 */
	public function list(ObjectRef $ref, array $filters = []): array {
		$schema = trim((string)($filters['schema'] ?? ''));
		if ($schema === '') {
			return [];
		}

		$register = trim((string)($filters['register'] ?? ''));
		if ($register === '') {
			$register = (string)$ref->register;
		}

		$answer = $this->lookup->read(
			register: $register,
			schema: $schema,
			key: (string)($filters['key'] ?? ''),
			refresh: in_array($filters['_refresh'] ?? false, [true, 'true', '1', 1], true)
		);
		if ($answer === null) {
			return [];
		}

		return [array_merge(['id' => $register . '/' . $schema . '/' . trim((string)($filters['key'] ?? ''))], $answer)];
	}//end list()
}//end class
