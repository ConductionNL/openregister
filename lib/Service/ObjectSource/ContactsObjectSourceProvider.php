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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\App\IAppManager;
use OCP\Contacts\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud address books.
 */
class ContactsObjectSourceProvider implements ObjectSourceProvider
{

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
     * Constructor.
     *
     * @param IManager        $contactsManager Nextcloud contacts (address book) manager.
     * @param IAppManager     $appManager      App availability checks.
     * @param LoggerInterface $logger          Logger for read failures.
     *
     * @return void
     */
    public function __construct(
        private readonly IManager $contactsManager,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function getId(): string
    {
        return 'contacts-source';
    }//end getId()

    /**
     * {@inheritDoc}
     *
     * Gated on the Contacts app: when it is not installed the bound schema
     * degrades to an empty list rather than erroring.
     *
     * @return bool True when the Contacts app is installed.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function isEnabled(): bool
    {
        try {
            return $this->appManager->isInstalled(self::REQUIRED_APP);
        } catch (Throwable $e) {
            return false;
        }
    }//end isEnabled()

    /**
     * {@inheritDoc}
     *
     * MUST return null when the contact is absent OR the acting user may not read
     * it, so the two cases are indistinguishable (no enumeration oracle).
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param string               $id       The contact UID.
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return ObjectEntity|null The virtual object, or null when absent/denied.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
    {
        foreach ($this->readContacts(search: $id) as $contact) {
            if ((string) ($contact['UID'] ?? '') === $id) {
                return $this->toObjectEntity(register: $register, schema: $schema, contact: $contact);
            }
        }

        return null;
    }//end find()

    /**
     * {@inheritDoc}
     *
     * Honours `filters.search`/`_search`, `limit` and `offset`. The result is
     * scoped by IManager to the acting user's readable address books.
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/search/limit/offset).
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return ObjectEntity[] The matching virtual objects.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
    {
        $search = (string) ($query['filters']['search'] ?? $query['_search'] ?? $query['search'] ?? '');
        $limit  = (int) ($query['limit'] ?? 200);
        $offset = (int) ($query['offset'] ?? 0);

        $contacts = $this->readContacts(search: $search);
        $contacts = array_slice($contacts, $offset, $limit);

        $objects = [];
        foreach ($contacts as $contact) {
            $objects[] = $this->toObjectEntity(register: $register, schema: $schema, contact: $contact);
        }

        return $objects;
    }//end findAll()

    /**
     * {@inheritDoc}
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/search).
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return int The number of matching virtual objects.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
    {
        return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
    }//end count()

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
    private function readContacts(string $search): array
    {
        try {
            $contacts = $this->contactsManager->search($search, self::SEARCH_PROPERTIES);
        } catch (Throwable $e) {
            $this->logger->warning('[ObjectSource:contacts-source] could not list contacts: '.$e->getMessage());
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
    private function firstEmail(mixed $email): string
    {
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
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $contact  The IManager contact array.
     *
     * @return ObjectEntity The virtual object (never saved).
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function toObjectEntity(Register $register, Schema $schema, array $contact): ObjectEntity
    {
        $uid = (string) ($contact['UID'] ?? '');

        $data = [
            'id'       => $uid,
            'fullName' => (string) ($contact['FN'] ?? ''),
            'email'    => $this->firstEmail(email: ($contact['EMAIL'] ?? '')),
            'org'      => (string) ($contact['ORG'] ?? ''),
        ];

        $entity = new ObjectEntity();
        $entity->setUuid($uid);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($data);

        return $entity;
    }//end toObjectEntity()
}//end class
