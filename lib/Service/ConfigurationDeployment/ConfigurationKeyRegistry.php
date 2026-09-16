<?php

/**
 * ConfigurationKeyRegistry — which addresses may be drafted, and as what.
 *
 * Two reasons this is a closed vocabulary rather than "anything goes".
 *
 * The first is that a refusal has to be able to happen. A deployment that
 * accepts every key it is handed can never name a value that refused, and
 * REQ-CAD-002's second scenario would then be untestable by construction.
 *
 * The second is that some keys must NOT be deployable. `configuration_four_eyes`
 * is the control that governs deployments; letting it travel inside one would
 * let a set approve itself by turning the requirement off on the way in. It is
 * declared here as reserved, and refused by name.
 *
 * Keys under an open prefix (a register's own configuration, an integration,
 * a notification rule) are accepted at any layer with no type constraint,
 * because their shape belongs to whoever defined the prefix, not here.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
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
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * The declared configuration vocabulary.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ConfigurationLayer is a closed
 * vocabulary of compile-time constants. This class refuses an unknown layer by
 * asking it; injecting it would let a caller supply the answer to the question
 * being asked.
 */
class ConfigurationKeyRegistry {

	/**
	 * The value is a JSON object.
	 *
	 * @var string
	 */
	public const TYPE_OBJECT = 'object';

	/**
	 * The value is a string.
	 *
	 * @var string
	 */
	public const TYPE_STRING = 'string';

	/**
	 * The value is a boolean.
	 *
	 * @var string
	 */
	public const TYPE_BOOLEAN = 'boolean';

	/**
	 * The value is an integer.
	 *
	 * @var string
	 */
	public const TYPE_INTEGER = 'integer';

	/**
	 * Any shape, for keys whose schema belongs to their own prefix.
	 *
	 * @var string
	 */
	public const TYPE_ANY = 'any';

	/**
	 * The instance-layer keys the settings surface writes today, with the
	 * shape each one is stored in. Spelled exactly as the app config key, so
	 * a deployment writes where every existing reader already looks.
	 *
	 * @var array<string, string>
	 */
	private const DECLARED = [
		'rbac' => self::TYPE_OBJECT,
		'multitenancy' => self::TYPE_OBJECT,
		'retention' => self::TYPE_OBJECT,
		'archival' => self::TYPE_OBJECT,
		'organisation' => self::TYPE_OBJECT,
		'llm' => self::TYPE_OBJECT,
		'fileManagement' => self::TYPE_OBJECT,
		'objectManagement' => self::TYPE_OBJECT,
		'solr' => self::TYPE_OBJECT,
		'search_backend' => self::TYPE_OBJECT,
		'flow_run_retention_days' => self::TYPE_STRING,
		'flow_audit_enabled' => self::TYPE_BOOLEAN,
		'flow_oversight_enabled' => self::TYPE_BOOLEAN,
		'flow_kill_switch' => self::TYPE_BOOLEAN,
		'party_query_cap' => self::TYPE_INTEGER,
	];

	/**
	 * Keys that may never travel inside a deployment, and why.
	 *
	 * @var array<string, string>
	 */
	private const RESERVED = [
		'configuration_four_eyes' => 'the approval requirement governs deployments and cannot be changed by one',
		'configuration_drafting' => 'the drafting switch governs deployments and cannot be changed by one',
		'configuration_draft_set' => 'a set that could move where drafts land could redirect its own review',
	];

	/**
	 * Prefixes whose keys are accepted at any layer with no type constraint.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_PREFIXES = [
		'register.',
		'schema.',
		'integration.',
		'notification.',
		'lifecycle.',
		'permission.',
		'bundle.',
	];

	/**
	 * Why an address may not hold a drafted value, when it may not.
	 *
	 * @param string $layer     The layer.
	 * @param string $configKey The configuration key.
	 *
	 * @return string|null The reason, or null when the address is fine.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function refusalFor(string $layer, string $configKey): ?string {
		if (ConfigurationLayer::isKnown($layer) === false) {
			return sprintf('unknown layer "%s"', $layer);
		}

		if (isset(self::RESERVED[$configKey]) === true) {
			return sprintf('"%s" is reserved: %s', $configKey, self::RESERVED[$configKey]);
		}

		if ($this->isOpenKey(configKey: $configKey) === true) {
			return null;
		}

		if (isset(self::DECLARED[$configKey]) === false) {
			return sprintf(
				'"%s" is not a declared configuration key and matches no open prefix',
				$configKey
			);
		}

		if ($layer !== ConfigurationLayer::INSTANCE) {
			return sprintf(
				'"%s" is an instance setting and cannot be set at the %s layer',
				$configKey,
				$layer
			);
		}

		return null;

	}//end refusalFor()

	/**
	 * The declared shape of a key.
	 *
	 * @param string $configKey The configuration key.
	 *
	 * @return string One of the TYPE_* constants.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function typeOf(string $configKey): string {
		return (self::DECLARED[$configKey] ?? self::TYPE_ANY);

	}//end typeOf()

	/**
	 * Why a value does not match its key's declared shape, when it does not.
	 *
	 * @param string $configKey The configuration key.
	 * @param mixed  $value     The proposed value.
	 *
	 * @return string|null The reason, or null when the value fits.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function valueRefusalFor(string $configKey, mixed $value): ?string {
		$type = $this->typeOf(configKey: $configKey);
		$fits = match ($type) {
			self::TYPE_OBJECT => is_array($value),
			self::TYPE_STRING => is_string($value),
			self::TYPE_BOOLEAN => is_bool($value),
			self::TYPE_INTEGER => is_int($value),
			default => true,
		};

		if ($fits === false) {
			return sprintf(
				'"%s" is declared as %s and the value is %s',
				$configKey,
				$type,
				get_debug_type($value)
			);
		}

		if (json_encode($value) === false) {
			return sprintf('"%s" holds a value that cannot be stored as JSON', $configKey);
		}

		return null;

	}//end valueRefusalFor()

	/**
	 * The vocabulary a caller may draft against.
	 *
	 * Served over the draft-set surface so an operator, and the leaf app
	 * staging a case type's configuration, can read which addresses exist
	 * instead of discovering them one refusal at a time.
	 *
	 * @return array<string, mixed> The declared instance keys, the open
	 *                              prefixes, and the keys no deployment may
	 *                              carry, each with the reason.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function vocabulary(): array {
		return [
			'instanceKeys' => self::DECLARED,
			'openPrefixes' => self::OPEN_PREFIXES,
			'reserved' => self::RESERVED,
			'layers' => ConfigurationLayer::ORDER,
		];

	}//end vocabulary()

	/**
	 * Whether a key belongs to an open prefix.
	 *
	 * @param string $configKey The configuration key.
	 *
	 * @return boolean True when a prefix claims it.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function isOpenKey(string $configKey): bool {
		foreach (self::OPEN_PREFIXES as $prefix) {
			if (str_starts_with($configKey, $prefix) === true) {
				return true;
			}
		}

		return false;

	}//end isOpenKey()
}//end class
