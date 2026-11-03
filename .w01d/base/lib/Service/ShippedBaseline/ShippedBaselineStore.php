<?php

/**
 * What the app shipped, kept beside what the instance runs (REQ-LCA-001).
 *
 * 🔑 IT REUSES THE DEPLOYMENT STORE RATHER THAN GROWING A SECOND ONE. The
 * configuration-as-a-deployment change (#3808) already owns a layered value
 * store with an `subject` layer and open key prefixes, and a second table for
 * "configuration we keep about a schema" would be two models of the same thing
 * drifting apart, one of which the effective-configuration explainer cannot
 * see. So a baseline is a subject-layer value under the `schema.` prefix, and
 * the explainer reaches it the same way it reaches everything else (D-6).
 *
 * 🔴 A BASELINE IS NEVER PARTIALLY WRITTEN. It carries the definition, the app,
 * the app version and the moment together, because a definition without the
 * version it came from cannot answer "which release did this instance diverge
 * from" two upgrades later, which is the question that makes divergence
 * answerable rather than merely visible (D-5).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use DateTime;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and records the shipped baseline of one subject.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ConfigurationLayer is a closed
 * vocabulary of compile-time constants, for the reason its own docblock gives.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
class ShippedBaselineStore {

	/**
	 * The configuration key a schema's baseline lives under.
	 *
	 * Under the `schema.` open prefix the key registry already accepts, so
	 * this needs no new vocabulary and no migration.
	 *
	 * @var string
	 */
	public const KEY_SCHEMA = 'schema.shippedBaseline';

	/**
	 * The configuration key a register's baseline lives under.
	 *
	 * @var string
	 */
	public const KEY_REGISTER = 'register.shippedBaseline';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationValueStore $values The layered value store from #3808.
	 * @param LoggerInterface         $logger The logger.
	 */
	public function __construct(
		private readonly ConfigurationValueStore $values,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The baseline recorded for one subject, or null when there is none.
	 *
	 * 🔴 NULL AND `[]` ARE DIFFERENT ANSWERS. No baseline means nobody has ever
	 * recorded what this schema was shipped as, and the caller must fall back
	 * to today's behaviour. An empty baseline would mean the app shipped
	 * nothing, and comparing against it reports every property as a local
	 * addition.
	 *
	 * @param string $subject The subject reference, e.g. `schema:zaak`.
	 * @param string $configKey Which baseline, {@see self::KEY_SCHEMA}.
	 *
	 * @return array{definition: array<string, mixed>, app: string, appVersion: string, recordedAt: string}|null The baseline.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function read(string $subject, string $configKey = self::KEY_SCHEMA): ?array {
		try {
			$snapshot = $this->values->read(
				layer: ConfigurationLayer::SUBJECT,
				layerRef: $subject,
				configKey: $configKey
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ShippedBaselineStore] baseline unreadable for ' . $subject . ': ' . $e->getMessage()
			);
			return null;
		}

		if ($snapshot->present === false) {
			return null;
		}

		$stored = $snapshot->value;
		if (is_array($stored) === false || is_array(($stored['definition'] ?? null)) === false) {
			return null;
		}

		return [
			'definition' => $stored['definition'],
			'app' => (string)($stored['app'] ?? ''),
			'appVersion' => (string)($stored['appVersion'] ?? ''),
			'recordedAt' => (string)($stored['recordedAt'] ?? ''),
		];
	}//end read()

	/**
	 * Record what an app shipped for one subject.
	 *
	 * Returns whether it was written. NEVER THROWS: the descriptor import is
	 * what the caller is really doing, and failing to keep a baseline must not
	 * fail an upgrade. A missing baseline degrades to today's behaviour, which
	 * is the state every instance is in before this ships.
	 *
	 * @param string               $subject    The subject reference.
	 * @param array<string, mixed> $definition What the app ships.
	 * @param string               $app        The app.
	 * @param string               $appVersion The app version.
	 * @param string               $configKey  Which baseline.
	 *
	 * @return bool True when it was recorded.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function record(
		string $subject,
		array $definition,
		string $app,
		string $appVersion,
		string $configKey = self::KEY_SCHEMA
	): bool {
		try {
			$this->values->write(
				layer: ConfigurationLayer::SUBJECT,
				layerRef: $subject,
				configKey: $configKey,
				value: [
					'definition' => $definition,
					'app' => $app,
					'appVersion' => $appVersion,
					'recordedAt' => (new DateTime())->format(DATE_ATOM),
				],
				deploymentUuid: null,
				actor: null
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ShippedBaselineStore] baseline not recorded for ' . $subject . ': ' . $e->getMessage()
			);
			return false;
		}

		return true;
	}//end record()

	/**
	 * The subject reference of a schema slug.
	 *
	 * One spelling in one place: two spellings of the same address give the
	 * same baseline two rows, and then a comparison silently reads the wrong
	 * one.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function schemaSubject(string $slug): string {
		return ('schema:' . $slug);
	}//end schemaSubject()
}//end class
