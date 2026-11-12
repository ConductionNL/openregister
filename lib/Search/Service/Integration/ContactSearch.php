<?php

/**
 * The contacts leaf's name search.
 *
 * Finds contacts by part of a name, an e-mail address or an organisation
 * through Nextcloud's contacts manager. The manager only searches the address
 * books registered for the current user, which are the ones that user may
 * read, so this class adds no second reach of its own and widens nothing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md#requirement-the-contacts-leaf-offers-a-name-search
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Integration;

use OCP\Contacts\IManager;
use Psr\Log\LoggerInterface;

/**
 * Name search over the current user's readable address books.
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md#requirement-the-contacts-leaf-offers-a-name-search
 */
class ContactSearch {

	/**
	 * The shortest query that is searched. One letter matches most of an
	 * address book and tells the reader nothing.
	 *
	 * @var int
	 */
	public const MIN_QUERY_LENGTH = 2;

	/**
	 * The most rows one search answers.
	 *
	 * @var int
	 */
	public const LIMIT = 25;

	/**
	 * The vCard properties a query is matched against.
	 *
	 * @var string[]
	 */
	private const SEARCH_PROPERTIES = ['FN', 'EMAIL', 'ORG'];

	/**
	 * Constructor.
	 *
	 * @param IManager        $contactsManager Nextcloud's contacts manager.
	 * @param LoggerInterface $logger          Diagnostics.
	 */
	public function __construct(
		private readonly IManager $contactsManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Contacts matching the query.
	 *
	 * @param string $query Part of a name, e-mail address or organisation.
	 *
	 * @return array<int, array{uid: string, fullName: string, email: string, organisation: string, addressbook: string}> The rows.
	 *
	 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md#requirement-the-contacts-leaf-offers-a-name-search
	 */
	public function search(string $query): array {
		$query = trim($query);
		if (mb_strlen($query) < self::MIN_QUERY_LENGTH || $this->contactsManager->isEnabled() === false) {
			return [];
		}

		try {
			$found = $this->contactsManager->search($query, self::SEARCH_PROPERTIES, ['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ContactSearch] The contacts search failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		return $this->distinctRows(found: $found);
	}//end search()

	/**
	 * One row per contact uid, in the order found, capped at LIMIT; contacts without a uid are skipped.
	 *
	 * @param array<int, array<string, mixed>> $found The contacts manager's matches.
	 *
	 * @return array<int, array{uid: string, fullName: string, email: string, organisation: string, addressbook: string}> The rows.
	 */
	private function distinctRows(array $found): array {
		$rows = [];
		foreach ($found as $contact) {
			$uid = (string)($contact['UID'] ?? '');
			if ($uid === '' || isset($rows[$uid]) === true) {
				continue;
			}

			$rows[$uid] = self::row(uid: $uid, contact: $contact);
		}

		return array_slice(array_values($rows), 0, self::LIMIT);
	}//end distinctRows()

	/**
	 * The row for one contact.
	 *
	 * @param string               $uid     The contact's uid.
	 * @param array<string, mixed> $contact The contact as the contacts manager returns it.
	 *
	 * @return array{uid: string, fullName: string, email: string, organisation: string, addressbook: string}
	 */
	private static function row(string $uid, array $contact): array {
		return [
			'uid' => $uid,
			'fullName' => (string)($contact['FN'] ?? ''),
			'email' => self::first(value: ($contact['EMAIL'] ?? '')),
			'organisation' => self::first(value: ($contact['ORG'] ?? '')),
			'addressbook' => (string)($contact['addressbook-key'] ?? ''),
		];
	}//end row()

	/**
	 * The first value of a vCard property that may hold a list.
	 *
	 * @param mixed $value The raw property value.
	 *
	 * @return string The first value, or ''.
	 */
	private static function first(mixed $value): string {
		if (is_array($value) === true) {
			$value = (reset($value) ?? '');
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end first()
}//end class
