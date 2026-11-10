<?php

/**
 * A halt for one organisation, scoped to one app and a node-type prefix.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Oversight
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Oversight;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Engage, release and read per-organisation halts.
 *
 * The instance kill switch stops every flow step; suspending an organisation
 * locks its people out of everything. A halt is narrower than both: it stops
 * one app's work for one organisation (flow steps whose node type starts with
 * the prefix, and whatever the app itself checks through haltFor()), and the
 * organisation's people keep working.
 *
 * The active halts are one small JSON list in app config, read on every flow
 * hop; their history (who engaged and released what, when, why) is on the
 * audit trail.
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */
class OrganisationHaltService implements OrganisationHaltServiceInterface {

	public const CONFIG_KEY = 'organisation_halts';

	public const ACTION_ENGAGED = 'organisation.halt.engaged';

	public const ACTION_RELEASED = 'organisation.halt.released';

	/**
	 * Constructor.
	 *
	 * @param OrganisationHaltRecords     $records       The app config holding the active halts and the audit trail holding their history.
	 * @param IUserSession                $session       Who is acting.
	 * @param OrganisationMapperInterface $organisations To refuse an organisation that does not exist.
	 * @param LoggerInterface             $logger        The logger.
	 */
	public function __construct(
		private readonly OrganisationHaltRecords $records,
		private readonly IUserSession $session,
		private readonly OrganisationMapperInterface $organisations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Engage a halt as the signed-in user.
	 *
	 * Who may engage is the caller's decision (OpenRegister's route lets an
	 * administrator; an app may let an organisation's owner); this method only
	 * refuses what it could not record properly.
	 *
	 * @param HaltRequest $request The organisation, app, reason and node-type prefix ('' means "<app>."); trimmed before use.
	 *
	 * @return array<string, string> The halt.
	 *
	 * @throws InvalidArgumentException Without an actor, organisation, app or reason, or when one is already engaged.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function engage(HaltRequest $request): array {
		$request = $request->trimmed();
		$user = $this->session->getUser();
		if ($user === null) {
			throw new InvalidArgumentException('A halt needs an actor, and there is no session.');
		}

		$this->assertEngageable(request: $request);

		$halt = [
			'id' => Uuid::v4()->toRfc4122(),
			'organisation' => $request->organisation,
			'app' => $request->app,
			'nodeTypePrefix' => $request->prefix(),
			'reason' => $request->reason,
			'engagedBy' => $user->getUID(),
			'engagedAt' => (new DateTime())->format(DateTimeInterface::ATOM),
		];

		$this->store(halts: array_merge($this->list(), [$halt]));
		$this->record(action: self::ACTION_ENGAGED, changed: $halt);

		return $halt;
	}//end engage()

	/**
	 * Release a halt as the signed-in user.
	 *
	 * @param string $id The halt id.
	 *
	 * @return bool False when no such halt is engaged.
	 *
	 * @throws InvalidArgumentException Without an actor.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function release(string $id): bool {
		if ($this->session->getUser() === null) {
			throw new InvalidArgumentException('Releasing a halt needs an actor, and there is no session.');
		}

		$kept = [];
		$released = null;
		foreach ($this->list() as $halt) {
			if ($halt['id'] === $id) {
				$released = $halt;
				continue;
			}

			$kept[] = $halt;
		}

		if ($released === null) {
			return false;
		}

		$this->store(halts: $kept);
		$this->record(action: self::ACTION_RELEASED, changed: $released);

		return true;
	}//end release()

	/**
	 * The engaged halts, optionally for one organisation.
	 *
	 * @param string|null $organisation The organisation uuid, or null for all.
	 *
	 * @return array<int, array<string, string>> The halts.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function list(?string $organisation=null): array {
		$raw = $this->records->appConfig->getValueString('openregister', self::CONFIG_KEY, '[]');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$halts = [];
		foreach ($decoded as $halt) {
			if (is_array($halt) === false || isset($halt['id'], $halt['organisation'], $halt['app'], $halt['nodeTypePrefix']) === false) {
				continue;
			}

			if ($organisation !== null && $halt['organisation'] !== $organisation) {
				continue;
			}

			$halts[] = array_map(static fn (mixed $value): string => (string) $value, $halt);
		}

		return $halts;
	}//end list()

	/**
	 * The halt that stops this organisation's work in this app, or null: the read an app asks.
	 *
	 * @param HaltLookup $lookup The organisation, the app and, optionally, the node type about to run.
	 *
	 * @return array<string, string>|null The halt, or null.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function haltFor(HaltLookup $lookup): ?array {
		foreach ($this->list(organisation: $lookup->organisation) as $halt) {
			if ($halt['app'] !== $lookup->app) {
				continue;
			}

			if ($lookup->nodeType !== null && str_starts_with($lookup->nodeType, $halt['nodeTypePrefix']) === false) {
				continue;
			}

			return $halt;
		}

		return null;
	}//end haltFor()

	/**
	 * The halts whose prefix matches this node type, for any organisation.
	 *
	 * @param string $nodeType The node type about to run.
	 *
	 * @return array<int, array<string, string>> The halts.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function haltsMatching(string $nodeType): array {
		return array_values(
			array_filter(
				$this->list(),
				static fn (array $halt): bool => ($nodeType !== '' && str_starts_with($nodeType, $halt['nodeTypePrefix']) === true)
			)
		);
	}//end haltsMatching()

	/**
	 * Refuse what could not be recorded properly.
	 *
	 * @param HaltRequest $request The organisation, app, reason and prefix.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When something is missing or already engaged.
	 */
	private function assertEngageable(HaltRequest $request): void {
		if ($request->app === '') {
			throw new InvalidArgumentException('A halt names the app whose work stops.');
		}

		if ($request->reason === '') {
			throw new InvalidArgumentException('A halt carries a reason.');
		}

		try {
			$this->organisations->findByUuid($request->organisation);
		} catch (Throwable $e) {
			throw new InvalidArgumentException('No organisation "' . $request->organisation . '" exists to halt.');
		}

		$this->assertNotEngaged(request: $request);
	}//end assertEngageable()

	/**
	 * Refuse a halt the organisation already has for the same app and prefix.
	 *
	 * @param HaltRequest $request The organisation, app and prefix.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is already engaged.
	 */
	private function assertNotEngaged(HaltRequest $request): void {
		foreach ($this->list(organisation: $request->organisation) as $halt) {
			if ($halt['app'] === $request->app && $halt['nodeTypePrefix'] === $request->prefix()) {
				throw new InvalidArgumentException('This halt is already engaged (id ' . $halt['id'] . ').');
			}
		}
	}//end assertNotEngaged()

	/**
	 * Write the active list.
	 *
	 * @param array<int, array<string, string>> $halts The halts.
	 *
	 * @return void
	 */
	private function store(array $halts): void {
		$this->records->appConfig->setValueString('openregister', self::CONFIG_KEY, (string) json_encode(array_values($halts)));
	}//end store()

	/**
	 * One row on the trail. Never throws.
	 *
	 * @param string                $action  The action.
	 * @param array<string, string> $changed The halt.
	 *
	 * @return void
	 */
	private function record(string $action, array $changed): void {
		try {
			$user = $this->session->getUser();

			$row = new AuditTrail();
			$row->setUuid(Uuid::v4()->toRfc4122());
			$row->setAction($action);
			$row->setUser($user?->getUID() ?? 'system');
			$row->setUserName($user?->getDisplayName() ?? 'System');
			$row->setChanged($changed);
			$row->setCreated(new DateTime());

			$this->records->audit->insertAuditTrails(entries: [$row]);
		} catch (Throwable $e) {
			$this->logger->error('[OrganisationHalt] the act happened but was not recorded: ' . $e->getMessage());
		}
	}//end record()
}//end class
