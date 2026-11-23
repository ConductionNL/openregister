<?php

/**
 * OpenRegister reverse lookup of the caller's own mail
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

use Exception;
use OCA\OpenRegister\Kernel\Contracts\ObjectReadCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\LinkedEntityServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * The objects linked to a message in the caller's own Mail account that the caller may read.
 *
 * LinkedEntityService::reverseLookup scans with RBAC and multitenancy off, so a caller who is
 * not an administrator gets it only through here: the Mail account must belong to the session
 * user before anything is scanned, and every object row is checked through ObjectReadCheckInterface
 * (RBAC and multitenancy on) before it is returned. Register, schema and organisation rows are
 * left out.
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-reverse-lookup-api-to-find-objects-by-mail-message-id
 */
final class OwnMailReverseLookup {
	/**
	 * The linked types this answers for; anything else stays admin only.
	 *
	 * @var array<int, string>
	 */
	private const MAIL_TYPES = ['mail', 'email'];

	/**
	 * Constructor.
	 *
	 * @param LinkedEntityServiceInterface $linkedEntityService The reverse lookup.
	 * @param EmailLinkServiceInterface    $emailLinks          Answers whether a Mail account is the caller's.
	 * @param ObjectReadCheckInterface     $readAccess          Whether the caller may read an object, under RBAC and tenancy.
	 * @param LoggerInterface              $logger              Logger.
	 */
	public function __construct(
		private readonly LinkedEntityServiceInterface $linkedEntityService,
		private readonly EmailLinkServiceInterface $emailLinks,
		private readonly ObjectReadCheckInterface $readAccess,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this lookup answers for a linked type.
	 *
	 * @param string $type The linked type.
	 *
	 * @return bool True for mail.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md#requirement-reverse-lookup-api-to-find-objects-by-mail-message-id
	 */
	public function handles(string $type): bool {
		return in_array($type, self::MAIL_TYPES, true);
	}//end handles()

	/**
	 * The readable objects linked to a message in the caller's own Mail account.
	 *
	 * @param string $type     The linked type, `mail` or `email`.
	 * @param string $entityId The message reference, `{accountId}/{messageId}`.
	 *
	 * @return JSONResponse The readable objects, or 403 when the mailbox is not the caller's.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md#requirement-reverse-lookup-api-to-find-objects-by-mail-message-id
	 */
	public function answer(string $type, string $entityId): JSONResponse {
		$accountId = $this->mailAccountOf(entityId: $entityId);
		if ($accountId === null) {
			return $this->mailboxRefused();
		}

		try {
			$this->emailLinks->assertAccountOwnership(accountId: $accountId);
		} catch (Exception $e) {
			return $this->mailboxRefused();
		}

		try {
			$readable = $this->readableObjects(rows: $this->linkedEntityService->reverseLookup($type, $entityId));
		} catch (Exception $e) {
			$this->logger->error(
				'[OwnMailReverseLookup] reverseLookup failed',
				['type' => $type, 'entityId' => $entityId, 'error' => $e->getMessage()]
			);

			return new JSONResponse(['error' => $e->getMessage()], 400);
		}

		return new JSONResponse(['results' => $readable, 'total' => count($readable)]);
	}//end answer()

	/**
	 * The Mail account id a `{accountId}/{messageId}` reference names, or null when it names none.
	 *
	 * @param string $entityId The message reference.
	 *
	 * @return int|null The account id.
	 */
	private function mailAccountOf(string $entityId): ?int {
		$parts = explode('/', $entityId, 2);
		if (count($parts) !== 2 || ctype_digit($parts[0]) === false || ctype_digit($parts[1]) === false) {
			return null;
		}

		$accountId = (int)$parts[0];
		if ($accountId <= 0) {
			return null;
		}

		return $accountId;
	}//end mailAccountOf()

	/**
	 * The object rows of a reverse lookup the caller may read; every other row is left out.
	 *
	 * @param array<int, array<string, mixed>> $rows The reverse lookup's rows.
	 *
	 * @return array<int, array<string, mixed>> The readable object rows.
	 */
	private function readableObjects(array $rows): array {
		$readable = [];
		foreach ($rows as $row) {
			if (($row['entityType'] ?? '') !== 'object') {
				continue;
			}

			$ref = new ObjectRef(
				register: (string)($row['register'] ?? ''),
				schema: (string)($row['schemaId'] ?? ''),
				id: (string)($row['uuid'] ?? '')
			);
			if ($this->readAccess->isReadable(ref: $ref) === true) {
				$readable[] = $row;
			}
		}

		return $readable;
	}//end readableObjects()

	/**
	 * The refusal for a mailbox that is not the caller's.
	 *
	 * @return JSONResponse 403
	 */
	private function mailboxRefused(): JSONResponse {
		return new JSONResponse(['error' => 'Mail account not accessible to this user'], 403);
	}//end mailboxRefused()
}//end class
