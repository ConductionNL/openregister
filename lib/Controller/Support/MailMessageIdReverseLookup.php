<?php

/**
 * OpenRegister reverse lookup of a mail by its RFC Message-ID
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git_id>
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The objects linked to a mail by its RFC `Message-ID`, for a caller who does not know the
 * Mail account and message ids (a mail read outside Nextcloud Mail, or a sent mail that only
 * has an outbound link).
 *
 * The match is exact on `rfc_message_id`: no prefix, no subject, nothing guessed. Every object is
 * read with RBAC and multitenancy on, so an object the caller may not read is left out as if it
 * had no link, the same scope `POST /api/emails/resolve` answers in.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
final class MailMessageIdReverseLookup {
	/**
	 * The linked types this answers for.
	 *
	 * @var array<int, string>
	 */
	private const MAIL_TYPES = ['mail', 'email'];

	/**
	 * Constructor.
	 *
	 * @param EmailLinkMapperInterface  $links   The email links, by `rfc_message_id`.
	 * @param ObjectOperationsInterface $objects Reads an object under RBAC and tenancy.
	 * @param LoggerInterface           $logger  Logger.
	 */
	public function __construct(
		private readonly EmailLinkMapperInterface $links,
		private readonly ObjectOperationsInterface $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The `Message-ID` a lookup names, with its angle brackets, or null when it names none.
	 *
	 * A Mail reference `{accountId}/{messageId}` carries no `@`, so it is never read as one.
	 *
	 * @param string $type     The linked type.
	 * @param string $entityId The reference from the route.
	 *
	 * @return string|null The `Message-ID` as stored, `<local@domain>`.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function messageIdOf(string $type, string $entityId): ?string {
		if (in_array($type, self::MAIL_TYPES, true) === false) {
			return null;
		}

		if (preg_match('/^<?([^<>\s@]+@[^<>\s@]+)>?$/', trim($entityId), $match) !== 1) {
			return null;
		}

		return '<'.$match[1].'>';
	}//end messageIdOf()

	/**
	 * The readable objects linked to a mail with this `Message-ID`.
	 *
	 * @param string $messageId The `Message-ID`, with its angle brackets.
	 *
	 * @return JSONResponse `{results, total}`; each result names the header it matched on.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function answer(string $messageId): JSONResponse {
		try {
			$uuids = $this->links->objectUuidsByRfcMessageId(rfcMessageId: $messageId);
		} catch (Throwable $e) {
			$this->logger->error(
				'[MailMessageIdReverseLookup] lookup failed',
				['messageId' => $messageId, 'error' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'The mail links could not be read'], 500);
		}

		$results = [];
		foreach ($uuids as $uuid) {
			$object = $this->readable(uuid: $uuid);
			if ($object !== null) {
				$results[] = $this->row(object: $object, messageId: $messageId);
			}
		}

		return new JSONResponse(['results' => $results, 'total' => count($results)]);
	}//end answer()

	/**
	 * The object when the caller may read it, else null; a failed read is no read.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function readable(string $uuid): ?ObjectEntity {
		try {
			return $this->objects->find(
				id: $uuid,
				options: new ObjectFindOptions(render: false, audit: false),
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable) {
			return null;
		}
	}//end readable()

	/**
	 * One result row, in the shape of the reverse lookup's object rows.
	 *
	 * @param ObjectEntity $object    The readable object.
	 * @param string       $messageId The `Message-ID` it matched.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(ObjectEntity $object, string $messageId): array {
		return [
			'entityType' => 'object',
			'uuid'       => $object->getUuid(),
			'name'       => $object->getName(),
			'register'   => (int)$object->getRegister(),
			'schemaId'   => (int)$object->getSchema(),
			'matchedOn'  => 'Message-ID',
			'messageId'  => $messageId,
		];
	}//end row()
}//end class
