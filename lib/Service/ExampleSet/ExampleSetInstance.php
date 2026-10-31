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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The instance's address, and OpenRegister organisations found, created and deleted for an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
 */
class ExampleSetInstance {
	/**
	 * The most organisations one lookup reads.
	 */
	private const LIMIT = 1000;

	/**
	 * What delete() reports.
	 */
	public const DELETED = 'deleted';

	public const LEFT = 'left';

	public const FAILED = 'failed';

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
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
	 * @param array<string, mixed> $declared The declared organisation; a new one gets its quota and members.
	 * @param array<int, string>   $made     Uuids of the organisations this set created; such an existing one
	 *                                       gains the declared quota where it has none.
	 *
	 * @return array{uuid: string, created: bool, error: string}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-organisation-the-set-made-gains-the-quota-it-declares-later
	 */
	public function ensure(array $declared, array $made=[]): array {
		try {
			$found = $this->mapper()->findBySlug(slug: $declared['slug']);
			$uuid  = (string) $found->getUuid();
			$error = '';
			if (in_array($uuid, $made, true) === true) {
				$error = $this->fillQuota(organisation: $found, quota: (array) ($declared['quota'] ?? []));
			}

			return ['uuid' => $uuid, 'created' => false, 'error' => $error];
		} catch (DoesNotExistException) {
			// Not there: created below.
		} catch (Throwable $e) {
			// A lookup that failed says nothing about the organisation; creating one now could duplicate the slug.
			return ['uuid' => '', 'created' => false, 'error' => $e->getMessage()];
		}

		try {
			$organisation = new Organisation();
			$organisation->setName($declared['name']);
			$organisation->setSlug($declared['slug']);
			$organisation->setDescription($declared['description']);
			// A partner (ketenpartner) is an organisation of type partner; empty keeps the default.
			if (($declared['type'] ?? '') !== '') {
				$organisation->setType($declared['type']);
			}

			if (($declared['contactEmail'] ?? '') !== '') {
				$organisation->setContactEmail($declared['contactEmail']);
			}

			$quota = ($declared['quota'] ?? []);
			$organisation->setStorageQuota($quota['storage'] ?? null);
			$organisation->setRequestQuota($quota['requests'] ?? null);
			$organisation->setBandwidthQuota($quota['bandwidth'] ?? null);
			$organisation->setUserQuota($quota['users'] ?? null);
			$organisation->setObjectQuota($quota['objects'] ?? null);
			$organisation->setObjectQuotaSchema($quota['objectsSchema'] ?? null);
			foreach (($declared['members'] ?? []) as $member) {
				$organisation->addUser($member);
			}

			$saved = $this->mapper()->insert(entity: $organisation);
		} catch (Throwable $e) {
			return ['uuid' => '', 'created' => false, 'error' => $e->getMessage()];
		}

		return ['uuid' => (string) $saved->getUuid(), 'created' => true, 'error' => ''];
	}//end ensure()

	/**
	 * Give an organisation the declared quota where it has none; a quota it holds is never changed.
	 *
	 * @param Organisation         $organisation The organisation as stored.
	 * @param array<string, mixed> $quota        The declared quota.
	 *
	 * @return string The error, or '' when nothing was missing or it was written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-organisation-the-set-made-gains-the-quota-it-declares-later
	 */
	private function fillQuota(Organisation $organisation, array $quota): string {
		$fields  = [
			'storage'       => ['getStorageQuota', 'setStorageQuota'],
			'requests'      => ['getRequestQuota', 'setRequestQuota'],
			'bandwidth'     => ['getBandwidthQuota', 'setBandwidthQuota'],
			'users'         => ['getUserQuota', 'setUserQuota'],
			'objects'       => ['getObjectQuota', 'setObjectQuota'],
			'objectsSchema' => ['getObjectQuotaSchema', 'setObjectQuotaSchema'],
		];
		$changed = false;
		foreach ($fields as $key => [$getter, $setter]) {
			if (isset($quota[$key]) === true && $organisation->$getter() === null) {
				$organisation->$setter($quota[$key]);
				$changed = true;
			}
		}

		if ($changed === false) {
			return '';
		}

		try {
			$this->mapper()->update(entity: $organisation);
		} catch (Throwable $e) {
			return 'its quota could not be written: '.$e->getMessage();
		}

		return '';
	}//end fillQuota()

	/**
	 * Delete an organisation by uuid unless somebody else belongs to it.
	 *
	 * Only a lookup that says the organisation does not exist reads as gone;
	 * any other error leaves it in the record to be tried again. An
	 * organisation with a member that is neither an account the set made or
	 * adopted nor a leftover of an account that no longer exists is a tenant
	 * now, and deleting it would change what its objects mean and who sees
	 * them, so it stays.
	 *
	 * @param string                    $uuid       The organisation's uuid.
	 * @param (callable(string): bool)|null $ignorable Whether a member does not keep the organisation; null: none does.
	 *
	 * @return string `deleted` (also when it was already gone), `left` (it has other members) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function delete(string $uuid, ?callable $ignorable = null): string {
		try {
			$organisation = $this->mapper()->findByUuid(uuid: $uuid);
		} catch (DoesNotExistException) {
			return self::DELETED;
		} catch (Throwable) {
			return self::FAILED;
		}

		foreach (($organisation->getUsers() ?? []) as $member) {
			if ($ignorable === null || $ignorable((string) $member) === false) {
				return self::LEFT;
			}
		}

		try {
			$this->mapper()->delete(entity: $organisation);
		} catch (Throwable) {
			return self::FAILED;
		}

		return self::DELETED;
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
