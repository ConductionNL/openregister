<?php

/**
 * ConfigurationValidator: validates a schema configuration key by key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CalendarDateKindException;

/**
 * Validates a schema configuration, keeping what passes and recording what it drops.
 *
 * One instance validates one configuration. The keys it dropped are read back
 * with droppedKeys(), so Schema can hand them to SchemaMapper for a warning.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class ConfigurationValidator {

	/**
	 * Routes each key to its rule.
	 *
	 * @var ConfigurationEntryRules
	 */
	private readonly ConfigurationEntryRules $entries;

	/**
	 * Tells an unknown `x-openregister-*` key from a declared one.
	 *
	 * @var AnnotationVocabulary
	 */
	private readonly AnnotationVocabulary $vocabulary;

	/**
	 * The keys dropped so far, in the order they were met.
	 *
	 * @var array<int, string>
	 */
	private array $dropped = [];

	/**
	 * Constructor.
	 *
	 * @param array|null $properties The schema's declared properties, which a write-only path must be rooted at.
	 */
	public function __construct(?array $properties) {
		$this->entries = new ConfigurationEntryRules(properties: $properties);
		$this->vocabulary = new AnnotationVocabulary();
	}//end __construct()

	/**
	 * Validate configuration array
	 *
	 * @param array $configuration Configuration array to validate
	 *
	 * @throws \InvalidArgumentException If validation fails
	 *
	 * @return array Validated configuration
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-schema-configuration-must-accept-and-persist-the-object-slug-keys
	 */
	public function validate(array $configuration): array {
		$validatedConfig = [];
		foreach ($configuration as $key => $value) {
			$validatedConfig = $this->withEntry(validated: $validatedConfig, key: $key, value: $value);
		}

		return $validatedConfig;
	}//end validate()

	/**
	 * The keys dropped by validate(), in the order they were met.
	 *
	 * @return array<int, string>
	 */
	public function droppedKeys(): array {
		return $this->dropped;
	}//end droppedKeys()

	/**
	 * The configuration so far with one key validated into it.
	 *
	 * Per-key isolation (#419): a bad VALUE for one config key must never
	 * lose the whole configuration. Direct API saves surface the throw as
	 * a 400, but on APP IMPORT the throw is silently swallowed upstream and
	 * every OTHER key — x-openregister-mcp, x-openregister-lifecycle,
	 * x-openregister-calculations, mailObjectTemplate — is lost with it
	 * (observed: pipelinq `lead` carried an invalid linkedType and lost its
	 * entire config incl. its MCP tools). Drop the offending key, record it
	 * so SchemaMapper can warn, and keep the rest of the configuration.
	 *
	 * @param array      $validated The configuration validated so far.
	 * @param int|string $key       Configuration key.
	 * @param mixed      $value     Raw value for the key.
	 *
	 * @throws \InvalidArgumentException If a key that must fail the whole save is invalid
	 *
	 * @return array The configuration with this key added when it is kept.
	 */
	private function withEntry(array $validated, int|string $key, mixed $value): array {
		try {
			$accepted = $this->entries->validateConfigurationEntry(key: $key, value: $value);
		} catch (InvalidArgumentException $e) {
			return $this->afterRefusal(validated: $validated, key: $key, refusal: $e);
		}

		foreach ($accepted as $kept) {
			$validated[$key] = $kept;
		}

		if ($accepted === [] && $this->vocabulary->isUnknownAnnotation(key: (string)$key) === true) {
			$this->dropped[] = (string)$key;
		}

		return $validated;
	}//end withEntry()

	/**
	 * Drop a refused key, or fail the whole save when the key must not degrade.
	 *
	 * The write-only path annotation is exempt from per-key
	 * isolation and MUST fail the whole save.
	 *
	 * For every other key, "drop the offending key and keep the
	 * rest" is a safe degradation: the corresponding feature
	 * simply does not fire. For THIS key the failure mode is
	 * inverted — dropping it means the schema saves successfully
	 * with its nested secrets NO LONGER PROTECTED. A typo'd path
	 * (`configuratio.authentication.keys`) would persist a schema
	 * that looks annotated to a reviewer, passes save, and serves
	 * the secret in cleartext on the next read. That is precisely
	 * the phantom-annotation class this repo has been bitten by,
	 * except fail-OPEN instead of merely inert. A security control
	 * must fail closed and loudly: re-throw so a direct API save
	 * 400s and an app import aborts rather than silently shipping
	 * an unprotected schema.
	 *
	 * A DECLARED DATE KIND IS EXEMPT FOR THE SAME REASON, INVERTED.
	 *
	 * Dropping `calendarProvider` as a whole is a safe degradation:
	 * the virtual calendar does not appear and somebody notices. A
	 * typo INSIDE the `dates` block is not: the schema saves, it
	 * looks annotated to whoever wrote it, and the feed publishes an
	 * agenda that is silently missing the term they just declared.
	 * Nobody reads an empty agenda and concludes the schema is
	 * wrong. So this one fails loudly, naming the property.
	 *
	 * @param array                    $validated The configuration validated so far.
	 * @param int|string               $key       The refused key.
	 * @param InvalidArgumentException $refusal   Why it was refused.
	 *
	 * @throws \InvalidArgumentException The refusal, for a key that must fail the whole save.
	 *
	 * @return array The configuration without the key, or with `draftLifetimeDays` kept as false.
	 */
	private function afterRefusal(array $validated, int|string $key, InvalidArgumentException $refusal): array {
		if ((string)$key === Schema::WRITEONLY_PATHS_ANNOTATION || $refusal instanceof CalendarDateKindException) {
			throw $refusal;
		}

		// An unreadable `draftLifetimeDays` is kept as `false` (drafts kept):
		// dropping it would turn a typing error into "remove after 30 days".
		if ($key === 'draftLifetimeDays') {
			$validated[$key] = false;
		}

		$this->dropped[] = (string)$key;

		return $validated;
	}//end afterRefusal()
}//end class
