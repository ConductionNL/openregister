<?php

/**
 * The objects one contact is linked to, resolved into rows a panel can render.
 *
 * 🔑 THE LINK KNOWS WHERE, THE OBJECT KNOWS WHAT. A `ContactLink` carries the
 * register, the schema and the object uuid; the title and the status live on
 * the object. So the panel needs one read per linked object, and this class is
 * where that read happens and where its failure is decided.
 *
 * 🔴 A READ THAT COMES BACK EMPTY IS NOT A LINK THAT DOES NOT EXIST. It is an
 * object this caller may not see, or one that has been deleted, and the two
 * are told apart by nothing this class can ask. Both are marked `readable:
 * false` and counted rather than dropped, because a panel that silently
 * shortens its own list tells the reader something false: that a contact is
 * involved in two cases when they are involved in five.
 *
 * 🔴 THE READ IS THE PLATFORM'S AND THE VERDICT IS THE PLATFORM'S. This class
 * asks `ObjectService` for the object with the caller's own identity and
 * believes the answer. It adds no check of its own, because a second opinion
 * about who may read an object is how two answers to one question get born —
 * and on a schema that configures no authorization the platform's answer is
 * "everyone", which is the consuming schema's decision to change, not this
 * class's to override.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a contact's links into resolved, grouped panel rows.
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */
class ContactCasesResolver {

	/**
	 * How many links one contact's panel resolves.
	 *
	 * One read per link, so an unbounded list is an unbounded number of reads
	 * to render a sidebar. A contact linked to two thousand objects is a
	 * mailing list rather than a person, and the panel says so by counting
	 * what it did not resolve.
	 *
	 * @var int
	 */
	public const MAX_LINKS = 100;

	/**
	 * The object fields a row may be titled by, in the order they are tried.
	 *
	 * @var array<int,string>
	 */
	private const TITLE_FIELDS = ['title', 'name', 'identifier', 'subject'];

	/**
	 * Constructor.
	 *
	 * @param ContactCasesPanel $panel  Groups the resolved rows.
	 * @param LoggerInterface   $logger Logger.
	 */
	public function __construct(
		private readonly ContactCasesPanel $panel,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The panel for one contact.
	 *
	 * @param array<int,array<string,mixed>> $links   The contact's links, already scoped to the caller's address books.
	 * @param callable                       $resolve `fn(string $register, string $schema, string $uuid): ?array` — the object, or null.
	 *
	 * @return array{groups:array<int,array<string,mixed>>,unreadable:int,total:int,truncated:bool}
	 *         The grouped panel, plus whether the link list itself was cut.
	 *
	 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
	 */
	public function panelFor(array $links, callable $resolve): array {
		$truncated = (count($links) > self::MAX_LINKS);
		$rows = [];

		foreach (array_slice($links, 0, self::MAX_LINKS) as $link) {
			if (is_array($link) === false) {
				continue;
			}

			$rows[] = $this->rowFor(link: $link, resolve: $resolve);
		}

		$panel = $this->panel->group(rows: $rows);
		$panel['truncated'] = $truncated;

		return $panel;
	}//end panelFor()

	/**
	 * One link as a row, resolved or marked unreadable.
	 *
	 * @param array<string,mixed> $link    The link.
	 * @param callable            $resolve The object reader.
	 *
	 * @return array<string,mixed> The row.
	 */
	private function rowFor(array $link, callable $resolve): array {
		$register = (string)($link['register'] ?? ($link['registerId'] ?? ''));
		$schema = (string)($link['schema'] ?? ($link['schemaId'] ?? ''));
		$uuid = (string)($link['objectUuid'] ?? '');

		$row = [
			'schema' => $schema,
			'schemaLabel' => (string)($link['schemaLabel'] ?? $schema),
			'objectUuid' => $uuid,
			'role' => (string)($link['role'] ?? ''),
			'title' => '',
			'status' => '',
			'url' => '',
			'readable' => false,
		];

		if ($uuid === '') {
			return $row;
		}

		try {
			$object = $resolve($register, $schema, $uuid);
		} catch (Throwable $e) {
			// A read that threw is not a link that does not exist. It is
			// counted, and the reason is logged where an administrator can
			// find it rather than rendered at a reader who cannot act on it.
			$this->logger->warning(
				'[ContactCasesResolver] a linked object could not be read for the contact panel',
				['objectUuid' => $uuid, 'exception' => $e->getMessage()]
			);

			return $row;
		}

		if (is_array($object) === false || $object === []) {
			return $row;
		}

		$row['readable'] = true;
		$row['title'] = $this->titleOf(object: $object, uuid: $uuid);
		$row['status'] = (string)($object['status'] ?? '');
		$row['url'] = (string)($object['url'] ?? '');

		return $row;
	}//end rowFor()

	/**
	 * What to call an object in the panel.
	 *
	 * An object with no title falls back to its uuid rather than to an empty
	 * line: a row a reader cannot name is still a row they can click, and a
	 * blank one reads as a rendering fault.
	 *
	 * @param array<string,mixed> $object The object.
	 * @param string              $uuid   Its uuid.
	 *
	 * @return string The title.
	 */
	private function titleOf(array $object, string $uuid): string {
		foreach (self::TITLE_FIELDS as $field) {
			$value = trim((string)($object[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return $uuid;
	}//end titleOf()
}//end class
