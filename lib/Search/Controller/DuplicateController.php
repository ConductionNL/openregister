<?php

/**
 * OpenRegister DuplicateController
 *
 * HTTP entry point for the read-only MDM duplicate-candidate surface.
 * {@see index()} delegates entirely to
 * {@see \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService::findDuplicates()}
 * — no dedup logic lives here, and the endpoint performs no merge or write of
 * any kind. Candidate pairs are RBAC- and tenant-scoped because
 * `DuplicateDetectionService` reads via `ObjectService::findAll`.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionServiceInterface;
use OCA\OpenRegister\Search\Service\Quality\ReviewedPair;
use OCA\OpenRegister\Search\Service\Quality\PairDismissal;
use OCA\OpenRegister\Search\Service\Quality\DedupRun;
use OCA\OpenRegister\Service\WriteCause;
use OCA\OpenRegister\Search\Controller\Support\DuplicateControllerServices;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use RuntimeException;
use Throwable;

class DuplicateController extends Controller {
	/**
	 * Duplicate-candidate detection service.
	 *
	 * @var DuplicateDetectionServiceInterface
	 */
	private readonly DuplicateDetectionServiceInterface $duplicates;

	/**
	 * Pairs a person has already ruled out.
	 *
	 * @var DismissedPairStore
	 */
	private readonly DismissedPairStore $dismissals;

	/**
	 * Object read path, to fingerprint a pair being dismissed.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Session, group manager and logger.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request  The current request.
	 * @param DuplicateControllerServices $services The duplicate-detection API services.
	 */
	public function __construct(IRequest $request, DuplicateControllerServices $services) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->duplicates    = $services->duplicates;
		$this->dismissals    = $services->dismissals;
		$this->objectService = $services->objectService;
		$this->session       = $services->session;
	}//end __construct()

	/**
	 * Paginated duplicate-candidate pairs for a register/schema, descending
	 * by similarity score. Read-only: no merge, no write, no side effects.
	 * Accepts an optional `threshold` (falls back to the schema's
	 * `x-openregister-dedup` annotation, then the service default) and
	 * `limit`/`offset` pagination params.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse JSON response with the paginated candidate pairs.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-2
	 */
	public function index(string $register, string $schema): JSONResponse {
		$threshold = $this->thresholdFromRequest(key: 'threshold');

		$limit = (int)$this->request->getParam('limit', 20);
		$offset = (int)$this->request->getParam('offset', 0);

		if ($limit <= 0) {
			$limit = 20;
		}

		if ($offset < 0) {
			$offset = 0;
		}

		try {
			$pairs = $this->duplicates->findDuplicates(
				request: new DedupRun(register: $register, schema: $schema, matchRules: null, threshold: $threshold)
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$total = count($pairs);
		$page = array_slice($pairs, $offset, $limit);

		return new JSONResponse(
			[
				'items' => $page,
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
			]
		);
	}//end index()

	/**
	 * Score an unsaved candidate against the stored objects of a
	 * register/schema and return what it would pair with.
	 *
	 * Read-only in the strongest sense: the body is scored and discarded. It
	 * is never given a uuid, never validated against the schema, and never
	 * reaches a write path, so a form may ask this on every keystroke without
	 * leaving anything behind.
	 *
	 * The candidate is the request body minus the reserved keys the object
	 * API uses for metadata, so a form can post the same shape it would POST
	 * to create and get an answer about it.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse The matches, with score, matched fields and matched rules.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function check(string $register, string $schema): JSONResponse {
		$candidate = $this->candidateFromRequest();

		$threshold = $this->thresholdFromRequest(key: '_threshold');

		try {
			$matches = $this->duplicates->checkCandidate(
				register: $register,
				schema: $schema,
				candidate: $candidate,
				threshold: $threshold
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			[
				'matches' => $matches,
				'total' => count($matches),
				'threshold' => $this->duplicates->effectiveThreshold(
					request: new DedupRun(register: $register, schema: $schema, threshold: $threshold)
				),
			]
		);
	}//end check()

	/**
	 * The posted body, with the routing keys and every control removed.
	 *
	 * The cut-off is `_threshold`, not `threshold`, on purpose. A schema is
	 * free to declare a property called `threshold`, a permit register
	 * plausibly does, and a control sharing that name would silently drop the
	 * candidate's own value out of the comparison: the exact class of quiet
	 * wrong answer this endpoint exists to avoid. The underscore prefix is
	 * already the API's reserved namespace (`_extend`, `_ids`, `_watching`,
	 * `_unread`, `_dedupOverride`), so nothing that starts with one can be a
	 * property.
	 *
	 * @return array<string, mixed> The candidate body.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	private function candidateFromRequest(): array {
		$candidate = $this->request->getParams();

		foreach (['register', 'schema', '_route', '@self', 'id'] as $reserved) {
			unset($candidate[$reserved]);
		}

		foreach (array_keys($candidate) as $key) {
			if (is_string($key) === true && str_starts_with($key, '_') === true) {
				unset($candidate[$key]);
			}
		}

		return $candidate;
	}//end candidateFromRequest()

	/**
	 * A caller-supplied score cut-off, or null to use the schema's.
	 *
	 * One definition for both entry points. The listing spells the parameter
	 * `threshold` and the check spells it `_threshold`, because the check's
	 * body IS candidate data and a schema may legitimately declare a property
	 * called `threshold`; what they must not differ on is how the value is
	 * read, which is why the spelling is a parameter here and the parsing is
	 * not duplicated.
	 *
	 * Anything that is not a number is ignored rather than refused: a stray
	 * cut-off is not worth failing a read over, and falling back to the
	 * schema's own threshold is the answer the caller would have got by not
	 * sending one.
	 *
	 * @param string $key The request parameter to read.
	 *
	 * @return float|null The cut-off, or null when none was usably supplied.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	private function thresholdFromRequest(string $key): ?float {
		$value = $this->request->getParam($key);
		if ($value === null || (string)$value === '' || is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end thresholdFromRequest()

	/**
	 * Record that two objects were reviewed and are NOT the same, so the
	 * scorer stops offering the pair.
	 *
	 * Gated by the schema's declared `dismissGroups`. ADR-023: the permission
	 * to say "these are different people" is a declaration on the schema, not
	 * an `isAdmin()` in a controller, because who is competent to make that
	 * judgement differs per register — a party registry and a permit register
	 * do not have the same reviewers.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse The stored dismissal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function dismiss(string $register, string $schema): JSONResponse {
		$objectA = (string)$this->request->getParam('objectA', '');
		$objectB = (string)$this->request->getParam('objectB', '');
		$reason = (string)$this->request->getParam('reason', '');

		$pair    = new ReviewedPair(first: $objectA, second: $objectB, registerSlug: $register, schemaSlug: $schema);
		$refusal = $this->dismissRefusal(pair: $pair);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->storeDismissal(pair: $pair, reason: $reason);
	}//end dismiss()

	/**
	 * Why a dismissal is refused: the pair is not two different objects (400),
	 * or the caller is in none of the schema's dismiss groups (403).
	 *
	 * @param ReviewedPair $pair The two uuids and the register and schema references.
	 *
	 * @return JSONResponse|null The refusal, or null when the dismissal may proceed.
	 */
	private function dismissRefusal(ReviewedPair $pair): ?JSONResponse {
		if ($this->isTwoDifferentObjects(pair: $pair) === false) {
			return new JSONResponse(
				['error' => 'A dismissal needs two different objects.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->mayDismiss(register: $pair->registerSlug, schema: $pair->schemaSlug) === false) {
			return $this->notInADismissGroup();
		}

		return null;
	}//end dismissRefusal()

	/**
	 * Whether a pair names two objects, and not the same one twice.
	 *
	 * @param ReviewedPair $pair The two uuids.
	 *
	 * @return bool
	 */
	private function isTwoDifferentObjects(ReviewedPair $pair): bool {
		return $pair->first !== '' && $pair->second !== '' && $pair->first !== $pair->second;
	}//end isTwoDifferentObjects()

	/**
	 * The 403 for a caller in none of the groups the schema allows to dismiss pairs.
	 *
	 * @return JSONResponse
	 */
	private function notInADismissGroup(): JSONResponse {
		return new JSONResponse(
			['error' => 'You are not in a group this schema allows to dismiss duplicate pairs.'],
			Http::STATUS_FORBIDDEN
		);
	}//end notInADismissGroup()

	/**
	 * Store the dismissal of an allowed pair, fingerprinted as the scorer will compare it.
	 *
	 * @param ReviewedPair $pair   The two uuids and the register and schema references.
	 * @param string       $reason Why the reviewer judged them different.
	 *
	 * @return JSONResponse The stored dismissal, or 404 when either object cannot be read.
	 */
	private function storeDismissal(ReviewedPair $pair, string $reason): JSONResponse {
		$fingerprint = $this->fingerprintOf(pair: $pair);
		if ($fingerprint === null) {
			return new JSONResponse(
				['error' => 'One of the objects could not be read.'],
				Http::STATUS_NOT_FOUND
			);
		}

		$row = $this->dismissals->dismiss(pair: $pair, dismissal: $this->dismissalOf(fingerprint: $fingerprint, reason: $reason));
		return new JSONResponse($row);
	}//end storeDismissal()

	/**
	 * The dismissal record, made by the signed-in user.
	 *
	 * @param string $fingerprint The pair fingerprint.
	 * @param string $reason      Why the reviewer judged them different.
	 *
	 * @return PairDismissal
	 */
	private function dismissalOf(string $fingerprint, string $reason): PairDismissal {
		return new PairDismissal(
			fingerprint: $fingerprint,
			reason: $reason,
			dismissedBy: ((string)($this->session->userSession->getUser()?->getUID() ?? ''))
		);
	}//end dismissalOf()

	/**
	 * Undo a dismissal, so the pair is offered again.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse The updated dismissal, or 404 when there was none.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function undismiss(string $register, string $schema): JSONResponse {
		$objectA = (string)$this->request->getParam('objectA', '');
		$objectB = (string)$this->request->getParam('objectB', '');

		if ($objectA === '' || $objectB === '') {
			return new JSONResponse(
				['error' => 'A reversal needs two objects.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->mayDismiss(register: $register, schema: $schema) === false) {
			return $this->notInADismissGroup();
		}

		$row = $this->dismissals->undismiss(
			pair: new ReviewedPair(first: $objectA, second: $objectB, registerSlug: $register, schemaSlug: $schema),
			reversedBy: ((string)($this->session->userSession->getUser()?->getUID() ?? ''))
		);

		if ($row === null) {
			return new JSONResponse(['error' => 'This pair has no dismissal to undo.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row);
	}//end undismiss()

	/**
	 * Whether the caller is in a group the schema allows to dismiss pairs.
	 *
	 * A schema that names none allows nobody, which is the same posture
	 * `overrideGroups` takes on the create-time block: the absence of a
	 * declaration is a declaration, and an implicit administrator bypass would
	 * make it silently untrue.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return bool True when the caller may dismiss.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	private function mayDismiss(string $register, string $schema): bool {
		$groups = $this->dismissGroups(register: $register, schema: $schema);
		if ($groups === []) {
			return false;
		}

		$user = $this->session->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->isInAnyGroup(user: $user, groups: $groups);
	}//end mayDismiss()

	/**
	 * The schema's declared `dismissGroups`, or an empty list when it declares none.
	 *
	 * @param string $register Register reference.
	 * @param string $schema   Schema reference.
	 *
	 * @return array<mixed> The declared groups, unfiltered.
	 */
	private function dismissGroups(string $register, string $schema): array {
		$annotation = $this->duplicates->dedupAnnotation(register: $register, schema: $schema);
		$groups     = ($annotation['dismissGroups'] ?? null);
		if (is_array($groups) === false) {
			return [];
		}

		return $groups;
	}//end dismissGroups()

	/**
	 * Whether the user is in one of the named groups; names that are not a non-empty string are skipped.
	 *
	 * @param IUser        $user   The signed-in user.
	 * @param array<mixed> $groups The declared group names.
	 *
	 * @return bool True at the first group the user is in.
	 */
	private function isInAnyGroup(IUser $user, array $groups): bool {
		foreach ($groups as $group) {
			if (is_string($group) === false || $group === '') {
				continue;
			}

			if ($this->session->groupManager->isInGroup($user->getUID(), $group) === true) {
				return true;
			}
		}

		return false;
	}//end isInAnyGroup()

	/**
	 * The fingerprint of the pair being dismissed, computed by the SAME
	 * function the scorer will later compare against.
	 *
	 * @param ReviewedPair $pair The two uuids and the register and schema references.
	 *
	 * @return string|null The fingerprint, or null when either object cannot be read.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function fingerprintOf(ReviewedPair $pair): ?string {
		try {
			$a = WriteCause::asLookup(fn () => $this->objectService->find(id: $pair->first));
			$b = WriteCause::asLookup(fn () => $this->objectService->find(id: $pair->second));
		} catch (Throwable $e) {
			return null;
		}

		if ($a === null || $b === null) {
			return null;
		}

		return $this->duplicates->pairFingerprint(
			payloads: [($a->getObject() ?? []), ($b->getObject() ?? [])],
			rules: $this->duplicates->effectiveRules(register: $pair->registerSlug, schema: $pair->schemaSlug)
		);
	}//end fingerprintOf()
}//end class
