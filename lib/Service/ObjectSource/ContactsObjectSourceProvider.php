<?php

/**
 * ContactsObjectSourceProvider — serves the `nc-contact` virtual schema's objects
 * live from the acting user's Nextcloud address books (read-only).
 *
 * The authoritative record is the vCard held by the Contacts / CardDAV backend;
 * this provider projects each contact returned by {@see \OCP\Contacts\IManager}
 * as a virtual ObjectEntity (uuid = contact UID; object = {id, fullName, email,
 * org}) and never writes back. {@see \OCP\Contacts\IManager::search()} runs in the
 * acting user's context and only returns contacts from address books the user can
 * read, so the projection is inherently user-scoped (denied == absent, no
 * enumeration oracle) — mirroring the scoping approach of the sibling directory
 * providers. System address book entries (the user directory, already projected as
 * `nc-user`) are skipped so the two projections do not overlap.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\Contacts\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud address books.
 */
class ContactsObjectSourceProvider implements ObjectSourceProvider {

	use CountsThroughFindAll;
	use InstalledAppCheck;

	/**
	 * NC app id whose install-state gates this provider.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'contacts';

	/**
	 * Searched vCard properties; an empty pattern LIKE-matches every contact.
	 *
	 * @var array<int, string>
	 */
	private const SEARCH_PROPERTIES = ['FN', 'EMAIL', 'ORG', 'UID'];

	/**
	 * App availability checks.
	 *
	 * @var IAppManager
	 */
	private readonly IAppManager $appManager;

	/**
	 * Logger for read failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IManager          $contactsManager Nextcloud contacts (address book) manager.
	 * @param OptionalAppBridge $app             Whether the app is installed, its services, and the log of failed reads.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IManager $contactsManager,
		OptionalAppBridge $app,
	) {
		$this->appManager = $app->appManager;
		$this->logger = $app->logger;
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function getId(): string {
		return 'contacts-source';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * MUST return null when the contact is absent OR the acting user may not read
	 * it, so the two cases are indistinguishable (no enumeration oracle).
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string $id The contact UID.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function find(SourcedTable $source, string $id): ?ObjectEntity {
		return $this->findContact(binding: $source->binding(), id: $id);
	}//end find()

	/**
	 * Read one object for the bound register and schema.
	 *
	 * @param SourcedObjectBinding $binding The register and schema the objects belong to.
	 * @param string               $id      The object id.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 */
	private function findContact(SourcedObjectBinding $binding, string $id): ?ObjectEntity {
		foreach ($this->readContacts(search: $id) as $contact) {
			if ((string)($contact['UID'] ?? '') === $id) {
				return $this->toObjectEntity(binding: $binding, contact: $contact);
			}
		}

		return null;
	}//end findContact()

	/**
	 * {@inheritDoc}
	 *
	 * Honours `filters.search`/`_search`, `limit` and `offset`. The result is
	 * scoped by IManager to the acting user's readable address books.
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $query Query (filters/search/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function findAll(SourcedTable $source, array $query = []): array {
		return $this->listContacts(binding: $source->binding(), query: $query);
	}//end findAll()

	/**
	 * Read one page of objects for the bound register and schema.
	 *
	 * @param SourcedObjectBinding $binding The register and schema the objects belong to.
	 * @param array<string, mixed> $query   Query (filters/search/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 */
	private function listContacts(SourcedObjectBinding $binding, array $query): array {
		$search = (string)($query['filters']['search'] ?? $query['_search'] ?? $query['search'] ?? '');
		$limit = (int)($query['limit'] ?? 200);
		$offset = (int)($query['offset'] ?? 0);

		$contacts = array_slice($this->readContacts(search: $search), $offset, $limit);

		$objects = [];
		foreach ($contacts as $contact) {
			$objects[] = $this->toObjectEntity(binding: $binding, contact: $contact);
		}

		return $objects;
	}//end listContacts()

	/**
	 * Read the contacts visible to the acting user, failing closed to an empty list.
	 *
	 * System address book entries (the user directory) are filtered out so this
	 * projection does not duplicate `nc-user`.
	 *
	 * @param string $search Optional search term ('' LIKE-matches every contact).
	 *
	 * @return array<int, array<string, mixed>> The vCard-derived contact arrays.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function readContacts(string $search): array {
		try {
			$contacts = $this->contactsManager->search($search, self::SEARCH_PROPERTIES);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:contacts-source] could not list contacts: ' . $e->getMessage());
			return [];
		}

		return array_values(
			array_filter(
				$contacts,
				static function ($contact) {
					return is_array($contact) === true
						&& ($contact['isLocalSystemBook'] ?? false) !== true
						&& ($contact['UID'] ?? '') !== '';
				}
			)
		);
	}//end readContacts()

	/**
	 * Normalise a vCard EMAIL value (string or array) to a single address.
	 *
	 * @param mixed $email The raw EMAIL value from IManager.
	 *
	 * @return string The first email address, or an empty string.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function firstEmail(mixed $email): string {
		if (is_array($email) === true) {
			$first = reset($email);
			if (is_string($first) === true) {
				return $first;
			}

			return '';
		}

		if (is_string($email) === true) {
			return $email;
		}

		return '';
	}//end firstEmail()

	/**
	 * Map a Nextcloud contact array onto a non-persisted virtual ObjectEntity.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param array<string, mixed> $contact The IManager contact array.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function toObjectEntity(SourcedObjectBinding $binding, array $contact): ObjectEntity {
		$uid = (string)($contact['UID'] ?? '');

		$data = [
			'id' => $uid,
			'fullName' => (string)($contact['FN'] ?? ''),
			'email' => $this->firstEmail(email: ($contact['EMAIL'] ?? '')),
			'org' => (string)($contact['ORG'] ?? ''),
		];

		return $binding->toEntity(uuid: $uid, data: $data);
	}//end toObjectEntity()
}//end class
