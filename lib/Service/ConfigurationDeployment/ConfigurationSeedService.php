<?php

/**
 * ConfigurationSeedService — a working default configuration, as a draft set.
 *
 * REQ-CAD-006, second scenario: an administrator on a fresh instance runs the
 * seed and gets the defaults as a draft set, ready to review and deploy. Until
 * now the only seeding OpenRegister did happened at repair time, which means it
 * happened to an administrator rather than by one, and it wrote live values
 * nobody had looked at.
 *
 * **Where the defaults come from, and why not from a table here.** Every
 * settings getter already answers its domain's default when the key is absent:
 * `getRbacSettingsOnly()` on a fresh instance IS the working default for rbac.
 * So the seed reads the getters and drafts what they answer. A table of
 * defaults in this file would be a second set that drifts the first time
 * somebody changes a handler, and the drift would be invisible: both numbers
 * would look plausible.
 *
 * **It never overwrites a key the instance already holds.** An administrator
 * running the seed on a configured instance is asking for the gaps to be
 * filled, not for their choices to be replaced by ours. A key app config
 * already holds is reported as skipped, by name, so the answer says what it
 * did rather than only what it changed.
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
 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\SettingsService;
use Throwable;

/**
 * Seed the working defaults into a draft set an administrator reviews.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ConfigurationLayer is a closed
 * vocabulary of compile-time constants. A settings default is always an
 * instance key.
 */
class ConfigurationSeedService {

	/**
	 * Which getter answers each domain's working default, and under which key
	 * of its answer the value sits.
	 *
	 * The getter is named rather than the default itself, so the seed and the
	 * settings screen can never disagree about what a default is.
	 *
	 * A null section means the getter answers the blob directly. Three of the
	 * eight wrap their answer and five do not, which is a difference worth
	 * writing down rather than rediscovering: reading `['retention']` off a
	 * getter that answers the retention blob itself would seed null.
	 *
	 * @var array<string, array{0: string, 1: string|null}>
	 */
	private const DEFAULT_SOURCES = [
		'rbac' => ['getRbacSettingsOnly', 'rbac'],
		'multitenancy' => ['getMultitenancySettingsOnly', 'multitenancy'],
		'organisation' => ['getOrganisationSettingsOnly', 'organisation'],
		'retention' => ['getRetentionSettingsOnly', null],
		'archival' => ['getArchivalSettingsOnly', null],
		'objectManagement' => ['getObjectSettingsOnly', null],
		'fileManagement' => ['getFileSettingsOnly', null],
		'llm' => ['getLLMSettingsOnly', null],
	];

	/**
	 * Constructor.
	 *
	 * @param ConfigurationDraftService $drafts   The draft half of the lifecycle.
	 * @param ConfigurationValueStore   $store    The live values.
	 * @param SettingsService           $settings The settings facade, read for its defaults.
	 */
	public function __construct(
		private readonly ConfigurationDraftService $drafts,
		private readonly ConfigurationValueStore $store,
		private readonly SettingsService $settings
	) {

	}//end __construct()

	/**
	 * Seed the working defaults as a draft set.
	 *
	 * @param string|null $name The name for the set, when the caller has one.
	 *
	 * @return array<string, mixed> The set, what was drafted, and what was skipped.
	 *
	 * @throws DeploymentRefusedException When the set cannot be opened.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	public function seed(?string $name = null): array {
		$set = $this->drafts->openSet(
			name: ($name ?? sprintf('working defaults seeded on %s', date('Y-m-d'))),
			description: 'the defaults this instance would run with, staged for review'
		);

		$seeded = [];
		$skipped = [];

		foreach (self::DEFAULT_SOURCES as $configKey => $source) {
			$live = $this->store->read(
				layer: ConfigurationLayer::INSTANCE,
				layerRef: null,
				configKey: $configKey
			);

			if ($live->present === true) {
				$skipped[] = ['key' => $configKey, 'reason' => 'this instance already sets it'];
				continue;
			}

			$value = $this->defaultFor(getter: $source[0], section: $source[1]);
			if ($value === null) {
				$skipped[] = ['key' => $configKey, 'reason' => 'the settings screen answers no default for it'];
				continue;
			}

			$this->drafts->draftValue(
				setUuid: (string)$set->getUuid(),
				layer: ConfigurationLayer::INSTANCE,
				layerRef: null,
				configKey: $configKey,
				value: $value
			);

			$seeded[] = ['key' => $configKey, 'value' => $value];
		}//end foreach

		return $this->answer(set: $set, seeded: $seeded, skipped: $skipped);

	}//end seed()

	/**
	 * The working default for one domain, read from the settings facade.
	 *
	 * A getter that throws is a domain this instance cannot answer for, which
	 * is a reason to skip it and say so, never a reason to fail the whole seed
	 * and leave an administrator with a half-filled set.
	 *
	 * @param string      $getter  The facade method answering the domain.
	 * @param string|null $section The key of its answer holding the value, when it wraps.
	 *
	 * @return array<string, mixed>|null The default, or null when there is none.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	private function defaultFor(string $getter, ?string $section): ?array {
		try {
			$answer = $this->settings->{$getter}();
		} catch (Throwable $exception) {
			return null;
		}

		$value = match ($section) {
			null => $answer,
			default => ($answer[$section] ?? null),
		};
		if (is_array($value) === false || $value === []) {
			return null;
		}

		return $value;

	}//end defaultFor()

	/**
	 * What the seed did, in the shape the screen and the API both read.
	 *
	 * @param ConfigurationDraftSet            $set     The set that was opened.
	 * @param array<int, array<string, mixed>> $seeded  What was drafted.
	 * @param array<int, array<string, mixed>> $skipped What was left alone.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	private function answer(ConfigurationDraftSet $set, array $seeded, array $skipped): array {
		$message = sprintf(
			'%d default%s staged as drafts. Review the set and deploy it to make them live.',
			count($seeded),
			match (count($seeded)) {
				1 => '',
				default => 's',
			}
		);

		if ($seeded === []) {
			$message = 'this instance already sets every default, so nothing was staged';
		}

		return [
			'set' => $set->jsonSerialize(),
			'seeded' => $seeded,
			'skipped' => $skipped,
			'message' => $message,
		];

	}//end answer()
}//end class
