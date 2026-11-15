<?php

/**
 * RegisterNameReader
 *
 * Reads the names a persons or place register holds, and nothing else (REQ-DKP-003).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Contacts\IManager as ContactsManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The names of a register source: the name property of each object of a
 * schema, read as the system since detection runs in background jobs, or the
 * full names of the contacts of an address book.
 *
 * Only names are returned: no record id or other value leaves this reader, so
 * nothing about the matched record can reach a relation.
 *
 * @spec openspec/changes/detection-knows-the-person/specs/person-aware-detection/spec.md#requirement-a-person-detection-is-checked-against-the-organisations-own-registers-before-it-is-proposed-req-dkp-003
 */
class RegisterNameReader {

	/**
	 * The most records a source is read for, per detection run.
	 *
	 * @var integer
	 */
	public const LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the object service lazily, for a schema source.
	 * @param ContactsManager    $contacts  The Nextcloud contacts, for an address book source.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ContactsManager $contacts,
	) {
	}//end __construct()

	/**
	 * The names a source holds.
	 *
	 * @param array<string, string> $source A normalised source (PersonRegisterSource).
	 *
	 * @return list<string> The names, as stored.
	 *
	 * @throws RuntimeException When the address book is not there.
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
	 */
	public function names(array $source): array {
		if (($source['type'] ?? null) === PersonRegisterSource::CONTACTS) {
			return $this->fromAddressBook(addressBook: (string) $source['addressBook']);
		}

		return $this->fromSchema(source: $source);
	}//end names()

	/**
	 * The full names of the contacts of one address book, by key or uri.
	 *
	 * @param string $addressBook The address book key or uri.
	 *
	 * @return list<string>
	 *
	 * @throws RuntimeException When the address book is not there.
	 */
	private function fromAddressBook(string $addressBook): array {
		foreach ($this->contacts->getUserAddressBooks() as $book) {
			if ($book->getKey() !== $addressBook && $book->getUri() !== $addressBook) {
				continue;
			}

			$names = [];
			foreach ((array) $book->search('', ['FN'], ['limit' => self::LIMIT]) as $contact) {
				$name = trim((string) ($contact['FN'] ?? ''));
				if ($name !== '') {
					$names[] = $name;
				}
			}

			return $names;
		}

		throw new RuntimeException('The address book '.$addressBook.' is not available');
	}//end fromAddressBook()

	/**
	 * The name property of each object of the source schema.
	 *
	 * @param array<string, string> $source The schema source.
	 *
	 * @return list<string>
	 */
	private function fromSchema(array $source): array {
		$objectService = $this->container->get(ObjectService::class);
		$found = $objectService->findAll(
			config: [
				'filters' => ['register' => $source['register'], 'schema' => $source['schema']],
				'limit' => self::LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		$names = [];
		foreach ($found as $entry) {
			$data = $entry;
			if ($entry instanceof ObjectEntity) {
				$data = $entry->getObject();
			}

			$name = '';
			if (is_array($data) === true) {
				$name = trim((string) ($data[$source['nameProperty']] ?? ''));
			}

			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end fromSchema()
}//end class
