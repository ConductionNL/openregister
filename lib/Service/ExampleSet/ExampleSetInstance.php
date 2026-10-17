<?php

/**
 * OpenRegister Example Set Instance
 *
 * What this instance offers an example set: its address, for
 * `{{instance:baseUrl}}` (Nextcloud's `overwrite.cli.url`), and the OpenRegister organisations (tenants, not objects) an example set refers
 * to with `{{organisation:slug-or-name}}`, and the ones its cast declares in
 * `openregisterOrganisations`. A declared organisation that is not on the
 * instance is created and recorded; remove deletes only what the set created.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The instance's address, and OpenRegister organisations found, created and deleted for an example set.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
 */
class ExampleSetInstance {
	/**
	 * The most organisations one lookup reads.
	 */
	private const LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the organisation mapper on first use.
	 * @param IConfig            $config    Nextcloud's system config.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * What `{{instance:...}}` fills: `baseUrl`, the address in `overwrite.cli.url`
	 * without a trailing slash. A value the instance does not set is left out,
	 * so its placeholder is refused rather than filled with nothing.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function values(): array {
		$baseUrl = rtrim($this->config->getSystemValueString('overwrite.cli.url', ''), '/');
		if ($baseUrl === '') {
			return [];
		}

		return ['baseUrl' => $baseUrl];
	}//end values()

	/**
	 * The uuid of every organisation on the instance, by slug and by name.
	 *
	 * A slug wins over a name that happens to be the same text.
	 *
	 * @return array<string, string>
	 *
	 * @phpstan-impure It reads the instance, which ensure() changes.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
	 */
	public function uuids(): array {
		try {
			$all = $this->mapper()->findAll(limit: self::LIMIT);
		} catch (Throwable) {
			return [];
		}

		$byName = [];
		$bySlug = [];
		foreach ($all as $organisation) {
			$uuid = (string) $organisation->getUuid();
			if ((string) $organisation->getName() !== '') {
				$byName[(string) $organisation->getName()] = $uuid;
			}

			if ((string) $organisation->getSlug() !== '') {
				$bySlug[(string) $organisation->getSlug()] = $uuid;
			}
		}

		return array_merge($byName, $bySlug);
	}//end uuids()

	/**
	 * Create an organisation with this slug unless one exists.
	 *
	 * @param array{name: string, slug: string, description: string} $declared The declared organisation.
	 *
	 * @return array{uuid: string, created: bool, error: string}
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
	 */
	public function ensure(array $declared): array {
		try {
			$found = $this->mapper()->findBySlug(slug: $declared['slug']);
			return ['uuid' => (string) $found->getUuid(), 'created' => false, 'error' => ''];
		} catch (Throwable) {
			// Not there: created below.
		}

		try {
			$organisation = new Organisation();
			$organisation->setName($declared['name']);
			$organisation->setSlug($declared['slug']);
			$organisation->setDescription($declared['description']);
			$saved = $this->mapper()->insert(entity: $organisation);
		} catch (Throwable $e) {
			return ['uuid' => '', 'created' => false, 'error' => $e->getMessage()];
		}

		return ['uuid' => (string) $saved->getUuid(), 'created' => true, 'error' => ''];
	}//end ensure()

	/**
	 * Delete an organisation by uuid; true when it is gone.
	 *
	 * @param string $uuid The organisation's uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function delete(string $uuid): bool {
		try {
			$organisation = $this->mapper()->findByUuid(uuid: $uuid);
		} catch (Throwable) {
			return true;
		}

		try {
			$this->mapper()->delete(entity: $organisation);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end delete()

	/**
	 * OpenRegister's organisation mapper.
	 *
	 * @return OrganisationMapper
	 */
	private function mapper(): OrganisationMapper {
		return $this->container->get(OrganisationMapper::class);
	}//end mapper()
}//end class
