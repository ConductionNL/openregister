<?php

/**
 * A federated recipient, judged by the permission layer as one more principal.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\RemoteRecipientDenialInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether the deny rules take a verb from the recipient of a federated share.
 *
 * A REMOTE PRINCIPAL IS A PRINCIPAL (design D-1). The share is the grant, and
 * it is already bounded by what its creator still holds. This adds the other
 * half: the recipient is reached by the same deny pass as a local caller, so an
 * administrator can take a verb from a federated recipient with the rule they
 * already write, `"deny": {"read": ["remote:alice@other.example"]}`, or from
 * every federated recipient with `remote:*`. Nothing about a share decides
 * that: the authorization blocks do, the same blocks a local caller is judged
 * by, through the same evaluator.
 *
 * A share made to a token rather than to a cloud id has no named recipient;
 * whoever presents the token is reached by `remote:*` only.
 *
 * FAIL CLOSED. A schema that cannot be read, or a cascade that cannot be
 * resolved, refuses: the object is not served.
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */
class RemoteRecipientGate {

	/**
	 * The prefix of a federated recipient's principal name.
	 *
	 * @var string
	 */
	public const PREFIX = 'remote:';

	/**
	 * The principal name reaching every federated recipient.
	 *
	 * @var string
	 */
	public const ANY_REMOTE = 'remote:*';

	/**
	 * Constructor.
	 *
	 * @param RemoteRecipientDenialInterface $permissions The one evaluator.
	 * @param SchemaMapperInterface $schemas     Finds an object's schema.
	 * @param LoggerInterface       $logger      Says why an object was not served.
	 */
	public function __construct(
		private readonly RemoteRecipientDenialInterface $permissions,
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The principal names a share's recipient is reached by.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return array<int, string> `remote:*`, and `remote:<cloud id>` when the share names one.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
	 */
	public static function principalsOf(FederatedShare $share): array {
		$principals = [self::ANY_REMOTE];
		$recipient = trim((string)($share->getSharedWith() ?? ''));
		if ($recipient !== '') {
			$principals[] = self::PREFIX . $recipient;
		}

		return $principals;
	}//end principalsOf()

	/**
	 * Whether a deny takes this verb on this object from the share's recipient.
	 *
	 * @param FederatedShare $share  The share being served.
	 * @param ObjectEntity   $object The object.
	 * @param string         $verb   The verb, `read` for a serve.
	 *
	 * @return bool True when the object must not be served or written for this verb.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
	 */
	public function refuses(FederatedShare $share, ObjectEntity $object, string $verb): bool {
		try {
			$schema = $this->schemas->find(id: (string)$object->getSchema(), _rbac: false, _multitenancy: false);
			$denial = $this->permissions->remoteRecipientDenial(
				schema: $schema,
				object: $object,
				principals: self::principalsOf(share: $share),
				action: $verb
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[RemoteRecipientGate] Could not judge a federated recipient; the object is not served',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);
			return true;
		}

		if ($denial === null) {
			return false;
		}

		$this->logger->info(
			message: '[RemoteRecipientGate] A deny rule took a verb from a federated recipient',
			context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $object->getUuid(), 'action' => $verb, 'principal' => ($denial['principal'] ?? null)]
		);

		return true;
	}//end refuses()
}//end class
