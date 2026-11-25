<?php

/**
 * OpenRegister ActingHumanGuard
 *
 * A machine write names the human it acts for, when the instance or the schema requires it (REQ-SAT-006).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ActingHumanRefusedException;
use OCA\OpenRegister\Kernel\Contracts\CallingTokenInterface;
use OCA\OpenRegister\Service\Delegation\DelegationRequest;
use OCA\OpenRegister\Service\Delegation\DelegationResolver;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The one check the object write path asks before a create, update or delete.
 *
 * Off unless the instance setting `api.requireActingHuman` is on, or the schema
 * declares `x-openregister-acting-human: required`. When it applies, a write by
 * a machine caller (a token, a Consumer, a service account, or a request with
 * no signed-in user) must name a human in `X-OpenRegister-Acting-User`, and the
 * caller must hold a live delegation from that human, as DelegationResolver
 * judges it. A verified pair is handed to {@see ActingHumanContext} so the
 * audit row of the write records it.
 *
 * Never affected: a signed-in person acting in their own name (a browser
 * session is the acting human), and reads, which do not call this. A system
 * operation (install, repair, seed import) runs without a request to carry the
 * header and writes under its own audit cause, so it is not a call this guard
 * judges.
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */
class ActingHumanGuard implements ActingHumanGuardInterface {

	/**
	 * The instance setting that turns the guard on for every schema.
	 *
	 * @var string
	 */
	public const SETTING = 'api.requireActingHuman';

	/**
	 * The schema annotation that turns the guard on for one schema.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-acting-human';

	/**
	 * The request header that names the acting human.
	 *
	 * @var string
	 */
	public const HEADER = 'X-OpenRegister-Acting-User';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig               $appConfig Holds the instance setting.
	 * @param IRequest                 $request   Carries the acting-user header.
	 * @param IUserSession             $session   The principal the request runs as.
	 * @param CallingTokenInterface    $tokens    The token or Consumer the request came with.
	 * @param MachineCredentialService $accounts  Says whether the principal is a service account.
	 * @param DelegationResolver       $resolver  Judges whether the principal may act as the named human.
	 * @param ActingHumanContext       $context   Receives the verified pair for the audit row.
	 * @param ITimeFactory             $time      The moment the delegation is judged at.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IRequest $request,
		private readonly IUserSession $session,
		private readonly CallingTokenInterface $tokens,
		private readonly MachineCredentialService $accounts,
		private readonly DelegationResolver $resolver,
		private readonly ActingHumanContext $context,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Refuse a machine write that names no human it may act for.
	 *
	 * @param Schema|null $schema The schema written to, read for its annotation.
	 *
	 * @return void
	 *
	 * @throws ActingHumanRefusedException When the write must name a human and does not, or may not act for them.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function assertMayWrite(?Schema $schema): void {
		if ($this->isRequired(schema: $schema) === false) {
			return;
		}

		$caller = $this->machineCaller();
		if ($caller === null) {
			return;
		}

		$named = trim($this->request->getHeader(self::HEADER));
		if ($named === '') {
			throw ActingHumanRefusedException::required();
		}

		$verdict = $this->resolver->resolve(
			request: new DelegationRequest(
				principal: $caller['principal'],
				actingAs: $named,
				scope: [],
				now: $this->time->getDateTime()
			)
		);
		if ($verdict->permitted === false) {
			throw ActingHumanRefusedException::notDelegated(actingUser: $named);
		}

		$this->context->record(actingUser: $named, actorVia: $caller['via']);
	}//end assertMayWrite()

	/**
	 * Whether the instance or the schema requires an acting human.
	 *
	 * @param Schema|null $schema The schema written to.
	 *
	 * @return bool True when the setting is on or the schema declares it required.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	public function isRequired(?Schema $schema): bool {
		$setting = strtolower(trim($this->appConfig->getValueString('openregister', self::SETTING, 'off')));
		if (in_array($setting, ['on', 'true', '1', 'yes'], true) === true) {
			return true;
		}

		if ($schema === null) {
			return false;
		}

		$declared = ($schema->getConfiguration() ?? [])[self::ANNOTATION] ?? null;
		if ($declared === true) {
			return true;
		}

		return is_string($declared) === true && strtolower(trim($declared)) === 'required';
	}//end isRequired()

	/**
	 * The machine principal behind this write and the credential it came through, or null for a person.
	 *
	 * @return array{principal: string, via: string}|null The caller, or null when a person or a system operation writes.
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
	 */
	private function machineCaller(): ?array {
		if (SystemOperationContext::isActive() === true) {
			return null;
		}

		$uid   = (string)($this->session->getUser()?->getUID() ?? '');
		$token = $this->tokens->callingToken();
		if ($token !== null) {
			$principal = $uid;
			if ($principal === '') {
				$principal = (string)($token['ownerUid'] ?? '');
			}

			return [
				'principal' => $principal,
				'via' => (string)($token['consumerUuid'] ?? $token['reference'] ?? $token['consumerName'] ?? ''),
			];
		}

		if ($uid === '') {
			return ['principal' => '', 'via' => 'system'];
		}

		if ($this->accounts->isServiceAccount(uid: $uid) === true) {
			return ['principal' => $uid, 'via' => $uid];
		}

		return null;
	}//end machineCaller()
}//end class
