<?php

/**
 * DeclaredProviderDecisions — records an administrator's decision on a declared provider.
 *
 * The write side of the approval plane. A decision is a new
 * `credentialProviderApproval` object, never an edit of an earlier one, so
 * OpenRegister's audit trail keeps every decision with who took it, when, and
 * the exact base URL and rule set the administrator saw. The decision is pinned
 * to the digest of the declaration as it is now: when the app later edits the
 * entry, the decision no longer applies and the declaration is pending again
 * (DeclaredProviderResolver).
 *
 * Only a declaration that needs a decision can get one. An unknown identifier,
 * an invalid declaration and a narrowing declaration (admitted without a click)
 * are refused, and so is a transition that means nothing: rejecting what is
 * already admitted (that is a revoke), revoking what is not admitted.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes approve, reject and revoke decisions as approval records.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */
class DeclaredProviderDecisions {

	/**
	 * Approve: the declaration is usable on its current digest.
	 *
	 * @var string
	 */
	public const APPROVE = 'approved';

	/**
	 * Reject: the pending declaration stays unusable on its current digest.
	 *
	 * @var string
	 */
	public const REJECT = 'rejected';

	/**
	 * Revoke: an admitted declaration becomes unusable on its current digest.
	 *
	 * @var string
	 */
	public const REVOKE = 'revoked';

	/**
	 * The admission states each decision may be taken from.
	 *
	 * @var array<string, list<string>>
	 */
	private const FROM = [
		self::APPROVE => [
			DeclaredProviderResolver::STATUS_PENDING,
			DeclaredProviderResolver::STATUS_REJECTED,
			DeclaredProviderResolver::STATUS_REVOKED,
		],
		self::REJECT  => [DeclaredProviderResolver::STATUS_PENDING],
		self::REVOKE  => [DeclaredProviderResolver::STATUS_ADMITTED],
	];

	/**
	 * Constructor.
	 *
	 * @param DeclaredProviderLoader    $loader        The discovered declarations.
	 * @param DeclaredProviderResolver  $resolver      The current admission state.
	 * @param DeclaredProviderApprovals $approvals     The read side, forgotten after a write.
	 * @param DeclarationValidator      $validator     Computes the digest the decision is pinned to.
	 * @param ObjectOperationsInterface $objectService Writes the approval record.
	 * @param LoggerInterface           $logger        Logs a failed write, secret-free.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
	 */
	public function __construct(
		private readonly DeclaredProviderLoader $loader,
		private readonly DeclaredProviderResolver $resolver,
		private readonly DeclaredProviderApprovals $approvals,
		private readonly DeclarationValidator $validator,
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a decision on a declared provider and answer its new admission state.
	 *
	 * @param string $identifier The `<appId>:<localId>` identifier.
	 * @param string $decision   One of APPROVE, REJECT, REVOKE.
	 * @param string $decidedBy  The deciding administrator's user id.
	 * @param string $note       An optional note with the decision.
	 *
	 * @return array{status: string, origin: string, lane: string, entry: array<string, mixed>|null}
	 *
	 * @throws DeclaredProviderDecisionRefused When the decision cannot be recorded.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
	 */
	public function decide(string $identifier, string $decision, string $decidedBy, string $note=''): array {
		if (array_key_exists($decision, self::FROM) === false) {
			throw new DeclaredProviderDecisionRefused(message: 'Unknown decision.', status: 400);
		}

		if (trim($decidedBy) === '') {
			throw new DeclaredProviderDecisionRefused(message: 'A decision needs the deciding administrator.', status: 401);
		}

		$declaration = $this->decidable(identifier: $identifier);
		$current     = $this->resolver->resolve(identifier: $identifier)['status'];
		if (in_array($current, self::FROM[$decision], true) === false) {
			throw new DeclaredProviderDecisionRefused(
				message: 'The declared provider is ' . $current . '; it cannot be ' . $decision . '.',
				status: 409
			);
		}

		$this->write(record: $this->record(declaration: $declaration, decision: $decision, decidedBy: $decidedBy, note: $note));
		$this->approvals->forget(identifier: $identifier);

		return $this->resolver->resolve(identifier: $identifier);
	}//end decide()

	/**
	 * The declaration behind an identifier, when it is one that needs a decision.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return array{app: string, entry: array<string, mixed>, valid: bool, reason: string, lane: string}
	 *
	 * @throws DeclaredProviderDecisionRefused When there is nothing to decide on.
	 */
	private function decidable(string $identifier): array {
		$declaration = null;
		if ($this->resolver->isDeclaredIdentifier(identifier: $identifier) === true) {
			$declaration = $this->loader->get(identifier: $identifier);
		}

		if ($declaration === null) {
			throw new DeclaredProviderDecisionRefused(message: 'No enabled app declares this provider.', status: 404);
		}

		if ($declaration['valid'] === false) {
			throw new DeclaredProviderDecisionRefused(message: 'The declaration is invalid; there is nothing to decide on.', status: 409);
		}

		if ($declaration['lane'] === DeclarationValidator::LANE_NARROWING) {
			throw new DeclaredProviderDecisionRefused(
				message: 'The declaration narrows a reviewed provider and is admitted without a decision.',
				status: 409
			);
		}

		return $declaration;
	}//end decidable()

	/**
	 * The approval record of a decision: who decided, when, on which digest, and what they saw.
	 *
	 * @param array{app: string, entry: array<string, mixed>} $declaration The declaration.
	 * @param string                                         $decision    The decision.
	 * @param string                                         $decidedBy   The deciding administrator.
	 * @param string                                         $note        The note.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $declaration, string $decision, string $decidedBy, string $note): array {
		$entry = $declaration['entry'];

		return [
			'providerIdentifier' => (string)$entry['identifier'],
			'declaringApp'       => $declaration['app'],
			'declarationDigest'  => $this->validator->digest(entry: $entry),
			'status'             => $decision,
			'decidedBy'          => $decidedBy,
			'decidedAt'          => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'decisionNote'       => trim($note),
			'baseUrl'            => (string)$entry['baseUrl'],
			'allowRulesSnapshot' => array_map(
				static fn (array $rule): array => ['method' => $rule['method'], 'pathPattern' => $rule['pathPattern']],
				(array)$entry['allowRules']
			),
		];
	}//end record()

	/**
	 * Write an approval record as a new object.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return void
	 *
	 * @throws DeclaredProviderDecisionRefused When the record could not be written.
	 */
	private function write(array $record): void {
		try {
			$this->objectService->saveObject(
				object: $record,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: CredentialBrokerService::REGISTER, schema: DeclaredProviderApprovals::SCHEMA)
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('[DeclaredProviderDecisions] the decision on "' . $record['providerIdentifier'] . '" could not be recorded');
			throw new DeclaredProviderDecisionRefused(message: 'The decision could not be recorded.', status: 500);
		}
	}//end write()
}//end class
