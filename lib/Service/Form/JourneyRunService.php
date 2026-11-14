<?php

/**
 * JourneyRunService: drive a journey run step by step and commit each writing step all or none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IL10N;
use Throwable;

/**
 * A run of a journey with no run object of its own (decisions 179 and 180).
 *
 * What a run has saved is its destination objects in status `draft`, saved
 * through FormSubmitService::saveDraft(). Objects are created or leave draft
 * only when a step declaring `writes[]` is answered, through
 * FormSubmitService::submit(), which writes in declared order, resolves
 * `{ "$write": "<as>" }` to an earlier write's id and deletes a step's earlier
 * writes when a later one is refused. An answer for a field the current step
 * and the steps before it do not declare is refused: the client does not
 * choose which fields are in scope.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
 */
class JourneyRunService {

	/**
	 * The owner scope of an anonymous run's draft: `journey-run:<token hash>`.
	 *
	 * The resume token itself is never stored; its hash is the draft's owner,
	 * so only the holder of the token reads or saves that draft.
	 *
	 * @var string
	 */
	public const TOKEN_SCOPE = 'journey-run';

	/**
	 * The bytes of randomness in a resume token.
	 *
	 * @var integer
	 */
	private const TOKEN_BYTES = 32;

	/**
	 * Wire the run.
	 *
	 * @param ObjectOperationsInterface  $objects    Reads the journey object.
	 * @param FormSubmitServiceInterface $submits    Saves drafts and commits writes, all or none.
	 * @param LocalConditionEvaluator    $conditions Evaluates `next` rules.
	 * @param IL10N                      $l10n       Translates refusals.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly FormSubmitServiceInterface $submits,
		private readonly LocalConditionEvaluator $conditions,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The journey a run may be started on, for this submitter.
	 *
	 * An unknown, unpublished, step-less or access-less journey answers the
	 * same 404, so the endpoint is not an oracle for which ids exist. An
	 * `authenticated` journey refuses an anonymous submitter with 401; a
	 * `minTrust` journey is refused with 403 until a trust level is supplied
	 * to the run, which fails closed rather than open.
	 *
	 * @param string        $journeyId The journey object's uuid.
	 * @param FormSubmitter $submitter Who runs it.
	 *
	 * @return JourneyDefinition The journey.
	 *
	 * @throws FormSubmitRefusedException 404 no such journey, 401 sign in first, 403 not allowed.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function resolve(string $journeyId, FormSubmitter $submitter): JourneyDefinition {
		$journey = new JourneyDefinition(id: $journeyId, body: $this->bodyOf(journeyId: $journeyId));
		$access  = $journey->access();
		if ($journey->isPublished() === false || $journey->stepIds() === [] || $access === null) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('This journey does not exist.'), status: 404);
		}

		if ($access === 'authenticated' && $submitter->subject === null) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('Sign in to continue this journey.'), status: 401);
		}

		if ($access === 'minTrust') {
			throw new FormSubmitRefusedException(message: $this->l10n->t('This journey requires a verified identity.'), status: 403);
		}

		return $journey;
	}//end resolve()

	/**
	 * Start a run: the first step and the fields it asks for.
	 *
	 * @param string        $journeyId The journey object's uuid.
	 * @param FormSubmitter $submitter Who runs it.
	 *
	 * @return array{journey: string, step: string, fields: list<string>} The first step.
	 *
	 * @throws FormSubmitRefusedException As resolve().
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
	 */
	public function start(string $journeyId, FormSubmitter $submitter): array {
		$journey = $this->resolve(journeyId: $journeyId, submitter: $submitter);
		$first   = $journey->stepIds()[0];

		return ['journey' => $journeyId, 'step' => $first, 'fields' => $journey->fieldsOf(stepId: $first)];
	}//end start()

	/**
	 * Answer a step: refuse out-of-scope fields, commit the step's writes, name the next step.
	 *
	 * A step without `writes[]` writes nothing; its answers are kept by the
	 * client or saved as a draft through save(). A step with `writes[]` hands
	 * the answers to FormSubmitService::submit(), all or none: a refused later
	 * write leaves none of the step's earlier writes behind, and the refusal's
	 * findings reach the caller.
	 *
	 * @param string               $journeyId      The journey object's uuid.
	 * @param string               $stepId         The step being answered.
	 * @param array<string, mixed> $answers        The answers so far, keyed by field name.
	 * @param FormSubmitter        $submitter      Who runs it; its draft, when set, is left by a single write.
	 * @param string|null          $idempotencyKey The Idempotency-Key for a writing step.
	 *
	 * @return array{journey: string, step: string, next: string|null, fields: list<string>, committed: array<string, mixed>|null} The outcome.
	 *
	 * @throws FormSubmitRefusedException As resolve(); 404 unknown step; 422 out-of-scope answer; any refusal of the submit.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
	 */
	public function answer(
		string $journeyId,
		string $stepId,
		array $answers,
		FormSubmitter $submitter,
		?string $idempotencyKey=null
	): array {
		$journey = $this->resolve(journeyId: $journeyId, submitter: $submitter);
		$this->assertInScope(journey: $journey, stepId: $stepId, answers: $answers);

		$committed = null;
		$writes    = $journey->writesOf(stepId: $stepId);
		if ($writes !== []) {
			$committed = $this->submits->submit(
				submission: new FormSubmission(
					writes: $writes,
					payload: $answers,
					submitter: $this->scoped(journeyId: $journeyId, submitter: $submitter)
				),
				idempotencyKey: $idempotencyKey
			);
		}

		$next = $journey->nextAfter(stepId: $stepId, answers: $answers, conditions: $this->conditions);
		$asks = [];
		if ($next !== null) {
			$asks = $journey->fieldsOf(stepId: $next);
		}

		return ['journey' => $journeyId, 'step' => $stepId, 'next' => $next, 'fields' => $asks, 'committed' => $committed];
	}//end answer()

	/**
	 * Save a run part-way: its answers into the draft of the journey's first destination.
	 *
	 * The draft is the destination object in status `draft` (decision 180); it
	 * may miss required properties and is checked on every other rule. Saving
	 * activates nothing. A signed-in filer's draft is theirs. An anonymous
	 * run's draft is owned by the hash of a single-purpose resume token: the
	 * first save mints the token and answers it once, a later save presents it.
	 *
	 * @param string               $journeyId The journey object's uuid.
	 * @param string               $stepId    The step reached.
	 * @param array<string, mixed> $answers   The answers so far, keyed by field name.
	 * @param FormSubmitter        $submitter Who runs it; its draft id, when set, is saved into.
	 * @param string|null          $token     The anonymous run's resume token, when it has one.
	 *
	 * @return array{id: string, status: string, token?: string} The draft, and a newly minted token.
	 *
	 * @throws FormSubmitRefusedException As resolve(); 404 unknown step; 422 out-of-scope answer or nowhere to save; any refusal of saveDraft().
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	public function save(string $journeyId, string $stepId, array $answers, FormSubmitter $submitter, ?string $token=null): array {
		$journey = $this->resolve(journeyId: $journeyId, submitter: $submitter);
		$this->assertInScope(journey: $journey, stepId: $stepId, answers: $answers);

		$destination = $this->firstWrite(journey: $journey);
		if ($destination === null) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('This journey writes nothing, so there is nothing to save.'), status: 422);
		}

		$minted = null;
		$owner  = null;
		if ($submitter->subject === null) {
			$token = $this->presentedToken(token: $token);
			if ($token === null) {
				$token  = self::mintToken();
				$minted = $token;
			}

			$owner = self::tokenOwner(token: $token);
		}

		$draft = $this->submits->saveDraft(
			submission: new FormSubmission(
				writes: [$destination],
				payload: $answers,
				submitter: $this->scoped(journeyId: $journeyId, submitter: $submitter, owner: $owner)
			)
		);
		if ($minted !== null) {
			$draft['token'] = $minted;
		}

		return $draft;
	}//end save()

	/**
	 * Resume a saved run at the step it reached, with its recorded answers.
	 *
	 * A signed-in filer resumes their own newest draft of the journey, or the
	 * one named; an anonymous filer presents the run's resume token. A token
	 * for another run, a run that does not exist, a missing token and an
	 * unknown journey all answer the same 404, so the endpoint is not an
	 * oracle for which runs or tokens exist. The answers are read back from
	 * the draft through the destination's mapping; the step is the first one,
	 * following the `next` rules, whose fields are not all answered.
	 *
	 * @param string        $journeyId The journey object's uuid.
	 * @param string|null   $runId     The run (its draft's uuid), or null for the newest.
	 * @param string|null   $token     The anonymous run's resume token.
	 * @param FormSubmitter $submitter Who resumes it.
	 *
	 * @return array{journey: string, run: string, step: string, fields: list<string>, answers: array<string, mixed>, updatedAt: string} The run.
	 *
	 * @throws FormSubmitRefusedException 404 no such run; 401 or 403 as resolve().
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	public function resume(string $journeyId, ?string $runId, ?string $token, FormSubmitter $submitter): array {
		try {
			$journey = $this->resolve(journeyId: $journeyId, submitter: $submitter);
		} catch (FormSubmitRefusedException $refused) {
			if ($refused->getStatus() === 404) {
				throw $this->noSuchRun();
			}

			throw $refused;
		}

		$destination = $this->firstWrite(journey: $journey);
		$owner       = $this->resumeOwner(token: $token);
		if ($destination === null || ($owner === null && $submitter->subject === null)) {
			throw $this->noSuchRun();
		}

		$draft = $this->ownDraft(
			destination: $destination,
			submitter: new FormSubmitter(subject: $submitter->subject, scope: $journeyId, draftId: $runId, owner: $owner)
		);
		$answers = self::answersFrom(write: $destination, data: $draft['data']);
		$step    = $this->reachedStep(journey: $journey, answers: $answers);

		return [
			'journey' => $journeyId,
			'run' => $draft['id'],
			'step' => $step,
			'fields' => $journey->fieldsOf(stepId: $step),
			'answers' => $answers,
			'updatedAt' => $draft['updatedAt'],
		];
	}//end resume()

	/**
	 * The draft owner a resume token stands for: `journey-run:<base64url sha256>`.
	 *
	 * @param string $token The resume token.
	 *
	 * @return DraftOwner The owner.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	public static function tokenOwner(string $token): DraftOwner {
		$hash = rtrim(strtr(base64_encode(hash('sha256', $token, true)), '+/', '-_'), '=');

		return DraftOwner::scoped(scope: self::TOKEN_SCOPE, reference: $hash);
	}//end tokenOwner()

	/**
	 * The answers a draft records: its properties mapped back to the fields that wrote them.
	 *
	 * Without a mapping the draft's properties are the answers; with one, each
	 * `{ field, property }` pair whose property the draft holds gives that
	 * field its value. Fixed values are the journey's, not answers.
	 *
	 * @param array<string, mixed> $write The destination write.
	 * @param array<string, mixed> $data  The draft's data.
	 *
	 * @return array<string, mixed> The answers, keyed by field name.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	public static function answersFrom(array $write, array $data): array {
		$mapping = $write['mapping'] ?? null;
		if (is_array($mapping) === false) {
			return array_filter(
				$data,
				static fn (string|int $name): bool => in_array((string)$name, ['id', 'uuid'], true) === false
					&& str_starts_with((string)$name, '_') === false
					&& str_starts_with((string)$name, '@') === false,
				ARRAY_FILTER_USE_KEY
			);
		}

		$answers = [];
		foreach ((array)($mapping['fields'] ?? []) as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name     = (string)($field['field'] ?? '');
			$property = (string)($field['property'] ?? '');
			if ($name !== '' && $property !== '' && array_key_exists($property, $data) === true) {
				$answers[$name] = $data[$property];
			}
		}

		return $answers;
	}//end answersFrom()

	/**
	 * The step a run reached: the first, following the `next` rules, with a field not yet answered.
	 *
	 * A run whose every step on its path is answered resumes at its last step.
	 *
	 * @param JourneyDefinition    $journey The journey.
	 * @param array<string, mixed> $answers The recorded answers.
	 *
	 * @return string The step id.
	 */
	private function reachedStep(JourneyDefinition $journey, array $answers): string {
		$current = $journey->stepIds()[0];
		$visited = [];
		while (isset($visited[$current]) === false) {
			$visited[$current] = true;
			$missing = array_diff($journey->fieldsOf(stepId: $current), array_keys($answers));
			if ($missing !== []) {
				return $current;
			}

			$next = $journey->nextAfter(stepId: $current, answers: $answers, conditions: $this->conditions);
			if ($next === null) {
				return $current;
			}

			$current = $next;
		}

		return $current;
	}//end reachedStep()

	/**
	 * The owner's draft: the one named, else the newest; any miss is the one 404.
	 *
	 * @param array<string, mixed> $destination The destination write.
	 * @param FormSubmitter        $submitter   The subject or token owner, and the run id.
	 *
	 * @return array{id: string, status: string, data: array<string, mixed>, updatedAt: string} The draft.
	 *
	 * @throws FormSubmitRefusedException 404 no such run.
	 */
	private function ownDraft(array $destination, FormSubmitter $submitter): array {
		if ($submitter->draftId === null) {
			$draft = $this->submits->findDraft(destination: $destination, submitter: $submitter);
			if ($draft === null) {
				throw $this->noSuchRun();
			}

			return $draft;
		}

		try {
			return $this->submits->readDraft(destination: $destination, submitter: $submitter);
		} catch (FormSubmitRefusedException $refused) {
			if ($refused->getStatus() === 404) {
				throw $this->noSuchRun();
			}

			throw $refused;
		}
	}//end ownDraft()

	/**
	 * Who a resume reads as: the token's owner when a token is presented, else the subject (null).
	 *
	 * @param string|null $token The presented token.
	 *
	 * @return DraftOwner|null The token owner, or null to take the signed-in subject.
	 */
	private function resumeOwner(?string $token): ?DraftOwner {
		$token = $this->presentedToken(token: $token);
		if ($token === null) {
			return null;
		}

		return self::tokenOwner(token: $token);
	}//end resumeOwner()

	/**
	 * A presented token, trimmed; null when none was sent.
	 *
	 * @param string|null $token The header value.
	 *
	 * @return string|null The token.
	 */
	private function presentedToken(?string $token): ?string {
		if ($token === null || trim($token) === '') {
			return null;
		}

		return trim($token);
	}//end presentedToken()

	/**
	 * A fresh resume token: 32 random bytes, base64url.
	 *
	 * @return string The token.
	 */
	private static function mintToken(): string {
		return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
	}//end mintToken()

	/**
	 * The one refusal for every run that cannot be resumed.
	 *
	 * @return FormSubmitRefusedException 404.
	 */
	private function noSuchRun(): FormSubmitRefusedException {
		return new FormSubmitRefusedException(message: $this->l10n->t('This run does not exist.'), status: 404);
	}//end noSuchRun()

	/**
	 * Refuse an unknown step, and every answer outside the step's scope.
	 *
	 * @param JourneyDefinition    $journey The journey.
	 * @param string               $stepId  The step answered.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return void
	 *
	 * @throws FormSubmitRefusedException 404 unknown step, 422 with one finding per out-of-scope field.
	 */
	private function assertInScope(JourneyDefinition $journey, string $stepId, array $answers): void {
		if ($journey->hasStep(stepId: $stepId) === false) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('This step does not exist.'), status: 404);
		}

		$allowed  = $journey->fieldsInScope(stepId: $stepId);
		$findings = [];
		foreach (array_keys($answers) as $field) {
			if (in_array((string)$field, $allowed, true) === false) {
				$findings[] = [
					'field' => (string)$field,
					'code' => 'out-of-step',
					'message' => $this->l10n->t('The field %1$s is not asked for at step %2$s.', [(string)$field, $stepId]),
				];
			}
		}

		if ($findings !== []) {
			throw new FormSubmitRefusedException(
				message: $this->l10n->t('Some answers are not asked for at this step.'),
				status: 422,
				findings: $findings
			);
		}
	}//end assertInScope()

	/**
	 * The first write any step declares: where a part-way run is saved as a draft.
	 *
	 * @param JourneyDefinition $journey The journey.
	 *
	 * @return array<string, mixed>|null The write; null when no step writes.
	 */
	private function firstWrite(JourneyDefinition $journey): ?array {
		foreach ($journey->stepIds() as $stepId) {
			$writes = $journey->writesOf(stepId: $stepId);
			if ($writes !== []) {
				return $writes[0];
			}
		}

		return null;
	}//end firstWrite()

	/**
	 * The submitter with the journey as the scope its key and upload tokens belong to.
	 *
	 * @param string          $journeyId The journey object's uuid.
	 * @param FormSubmitter   $submitter The caller's submitter.
	 * @param DraftOwner|null $owner     An owner to assert in place of the submitter's, for an anonymous draft.
	 *
	 * @return FormSubmitter The scoped submitter.
	 */
	private function scoped(string $journeyId, FormSubmitter $submitter, ?DraftOwner $owner=null): FormSubmitter {
		return new FormSubmitter(
			subject: $submitter->subject,
			scope: $journeyId,
			draftId: $submitter->draftId,
			owner: ($owner ?? $submitter->owner)
		);
	}//end scoped()

	/**
	 * The journey object's body; empty when it cannot be read.
	 *
	 * @param string $journeyId The journey object's uuid.
	 *
	 * @return array<string, mixed> The body.
	 */
	private function bodyOf(string $journeyId): array {
		try {
			$entity = $this->objects->find(id: $journeyId, options: new ObjectFindOptions(audit: false), _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return [];
		}

		if ($entity === null) {
			return [];
		}

		return $entity->getObject();
	}//end bodyOf()
}//end class
