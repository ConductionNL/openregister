<?php

/**
 * Sends a survey invitation: a link that answers without an account, or a blocked record with its reason.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survey
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * One invitation per call, with the fatigue limit applied to its respondent first.
 *
 * The answering token is an access link (`access-by-link-not-by-account`) over the
 * invitation object, not a second token scheme. The invitation keeps the link's
 * uuid in `token`, never its anchor: whoever may read invitations must not be able
 * to answer on a respondent's behalf. The anchor is handed back once, to the caller
 * who sends it.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Survey records, the object write path and the link
 *                                                service are the three things an invitation is.
 */
class SurveyInvitations {

	/**
	 * The app config key holding how many invitations one respondent may get in the period; 0 is no limit.
	 */
	public const FATIGUE_MAXIMUM_KEY = 'survey_fatigue_maximum';

	/**
	 * The app config key holding the fatigue period, in days.
	 */
	public const FATIGUE_PERIOD_KEY = 'survey_fatigue_period_days';

	/**
	 * The fatigue period when none is configured.
	 */
	public const DEFAULT_FATIGUE_PERIOD_DAYS = 90;

	/**
	 * How long the link outlives the invitation's expiry.
	 *
	 * The link answers 404 once it expires, and a 404 does not say why. Kept a while
	 * longer, the link lets a respondent who comes late read that the invitation
	 * expired, which is what the invitation itself decides.
	 */
	public const LINK_GRACE_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param SurveyRecords              $records   Reads the survey and the respondent's invitations.
	 * @param SurveyRules                $rules     The fatigue rule.
	 * @param ObjectOperationsInterface  $objects   Writes the invitation.
	 * @param ObjectAccessLinksInterface $links     Mints the answering link.
	 * @param IAppConfig                 $appConfig Holds the fatigue limit.
	 * @param ITimeFactory               $clock     The moment of sending.
	 */
	public function __construct(
		private readonly SurveyRecords $records,
		private readonly SurveyRules $rules,
		private readonly ObjectOperationsInterface $objects,
		private readonly ObjectAccessLinksInterface $links,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $clock,
	) {
	}//end __construct()

	/**
	 * Send one invitation, or record it blocked when the respondent is over the fatigue limit.
	 *
	 * Every refusal of the request itself (no respondent, no readable or future expiry,
	 * no survey) is raised before anything is written. A fatigue block is not a refusal:
	 * it is written, with state `blocked` and its reason, so the gap in the response
	 * data has a reason.
	 *
	 * @param SurveyInvitationRequest $request The invitation to send.
	 *
	 * @return array{invitation: array<string, mixed>, blocked: bool, reason: string, anchor: string|null}
	 *         The written invitation, whether it was blocked and why, and the link anchor to send.
	 *
	 * @throws InvalidArgumentException When the request cannot be sent at all.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function invite(SurveyInvitationRequest $request): array {
		$respondent = trim($request->respondent);
		if ($respondent === '') {
			throw new InvalidArgumentException('A survey invitation needs a respondent address.');
		}

		$now = $this->clock->now();
		$expiry = self::expiryOf(expiresAt: $request->expiresAt, now: $now);
		$within = SurveyRecords::placeIn(register: $request->register);
		$table = $this->invitationTable(within: $within, surveyUuid: $request->survey);

		$data = [
			'survey' => $request->survey,
			'subjectObject' => $request->subjectObject,
			'respondent' => $respondent,
		];

		$reason = $this->fatigueRefusal(within: $within, respondent: $respondent, now: $now);
		if ($reason !== null) {
			$data['state'] = SurveyRules::BLOCKED;
			$data['blockedReason'] = $reason;

			return [
				'invitation' => $this->write(table: $table, data: $data, uuid: null),
				'blocked' => true,
				'reason' => $reason,
				'anchor' => null,
			];
		}

		// The link is minted first, over the uuid the invitation will get, so the
		// invitation is written once and never exists without its token. A link
		// left over by a write that failed opens nothing: its subject never existed.
		$uuid = Uuid::v4()->toRfc4122();
		$link = $this->links->mintObjectLink(
			minter: $request->minter,
			objectUuid: $uuid,
			expiresAt: $expiry->add(new DateInterval('P'.self::LINK_GRACE_DAYS.'D'))->format(DateTimeInterface::ATOM),
			label: 'Survey invitation'
		);

		$data['state'] = SurveyRules::SENT;
		$data['expiresAt'] = $expiry->format(DateTimeInterface::ATOM);
		$data['token'] = $link['uuid'];

		return [
			'invitation' => $this->write(table: $table, data: $data, uuid: $uuid),
			'blocked' => false,
			'reason' => '',
			'anchor' => $link['anchor'],
		];
	}//end invite()

	/**
	 * The fatigue limit as configured; a maximum of 0 is no limit.
	 *
	 * @return FatigueLimit The limit.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function fatigueLimit(): FatigueLimit {
		$period = $this->appConfig->getValueInt('openregister', self::FATIGUE_PERIOD_KEY, self::DEFAULT_FATIGUE_PERIOD_DAYS);

		return new FatigueLimit(
			maximum: max(0, $this->appConfig->getValueInt('openregister', self::FATIGUE_MAXIMUM_KEY, 0)),
			periodDays: max(1, $period)
		);
	}//end fatigueLimit()

	/**
	 * How many invitations the respondent was sent in the period before a moment, whichever survey sent them.
	 *
	 * A blocked invitation was never sent, so it does not count: counting it would
	 * keep a respondent blocked by the record of having been blocked.
	 *
	 * @param ObjectEntity      $within     An object in the survey register.
	 * @param string            $respondent The respondent's address.
	 * @param int               $periodDays The period, in days.
	 * @param DateTimeImmutable $now        The moment the period ends.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function recentInvitations(ObjectEntity $within, string $respondent, int $periodDays, DateTimeImmutable $now): int {
		$since = $now->sub(new DateInterval('P'.max(1, $periodDays).'D'))->getTimestamp();
		$count = 0;
		foreach ($this->records->invitationsTo(within: $within, respondent: $respondent) as $invitation) {
			$state = (string)(((array)$invitation->getObject())['state'] ?? SurveyRules::SENT);
			$created = $invitation->getCreated();
			// An invitation with no creation time cannot be shown to be outside
			// the period, so it counts: a limit that leaks is no limit.
			if ($state !== SurveyRules::BLOCKED && ($created === null || $created->getTimestamp() >= $since)) {
				$count++;
			}
		}

		return $count;
	}//end recentInvitations()

	/**
	 * The fatigue refusal for this respondent now, or null when the limit allows another invitation.
	 *
	 * @param ObjectEntity      $within     An object in the survey register.
	 * @param string            $respondent The respondent's address.
	 * @param DateTimeImmutable $now        The moment of sending.
	 *
	 * @return string|null The reason, or null.
	 */
	private function fatigueRefusal(ObjectEntity $within, string $respondent, DateTimeImmutable $now): ?string {
		$limit = $this->fatigueLimit();
		if ($limit->maximum === 0) {
			return null;
		}

		return $this->rules->refuseForFatigue(
			recentInvitations: $this->recentInvitations(
				within: $within,
				respondent: $respondent,
				periodDays: $limit->periodDays,
				now: $now
			),
			limit: $limit
		);
	}//end fatigueRefusal()

	/**
	 * The invitation table of the survey's register, once the survey is known to exist there.
	 *
	 * @param ObjectEntity $within     An object in the survey register.
	 * @param string       $surveyUuid The survey's uuid.
	 *
	 * @return RegisterSchema The invitation table.
	 *
	 * @throws InvalidArgumentException When the register holds no such survey or no invitation schema.
	 */
	private function invitationTable(ObjectEntity $within, string $surveyUuid): RegisterSchema {
		try {
			$survey = $this->records->survey(within: $within, surveyUuid: $surveyUuid);
		} catch (Throwable $missing) {
			unset($missing);
			$survey = null;
		}

		if ($survey === null) {
			throw new InvalidArgumentException(sprintf("'%s' is not a survey in this register.", $surveyUuid));
		}

		$table = $this->records->tableOf(within: $within, slug: SurveyRecords::INVITATION);
		if ($table === null) {
			throw new InvalidArgumentException('This register holds no survey invitations.');
		}

		return $table;
	}//end invitationTable()

	/**
	 * Write the invitation; the survey register's rules decide, not the caller's rights.
	 *
	 * @param RegisterSchema       $table The invitation table.
	 * @param array<string, mixed> $data  The invitation.
	 * @param string|null          $uuid  The uuid to write it under, or null for a new one.
	 *
	 * @return array<string, mixed> The written invitation, with its id.
	 */
	private function write(RegisterSchema $table, array $data, ?string $uuid): array {
		$saved = $this->objects->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(
					register: (string)$table->register->getId(),
					schema: (string)$table->schema->getId()
				),
				uuid: $uuid
			),
			_rbac: false,
			_multitenancy: false
		);

		return array_merge((array)$saved->getObject(), ['id' => (string)$saved->getUuid()]);
	}//end write()

	/**
	 * The expiry as a moment after now.
	 *
	 * @param string            $expiresAt The expiry as given.
	 * @param DateTimeImmutable $now       The moment of sending.
	 *
	 * @return DateTimeImmutable The expiry.
	 *
	 * @throws InvalidArgumentException When it is missing, unreadable or not after now.
	 */
	private static function expiryOf(string $expiresAt, DateTimeImmutable $now): DateTimeImmutable {
		$raw = trim($expiresAt);
		if ($raw === '') {
			throw new InvalidArgumentException('A survey invitation needs an expiry.');
		}

		try {
			$expiry = new DateTimeImmutable($raw);
		} catch (Exception $unreadable) {
			unset($unreadable);
			throw new InvalidArgumentException(sprintf("'%s' is not a date this instance can read.", $raw));
		}

		if ($expiry <= $now) {
			throw new InvalidArgumentException('A survey invitation must expire in the future.');
		}

		return $expiry;
	}//end expiryOf()
}//end class
