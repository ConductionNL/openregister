<?php

/**
 * FormLinkService
 *
 * Wraps the NC Forms entities (forms + submissions) into a link-table
 * relation against OpenRegister objects. The Tier-2 forms leaf reads
 * this service via FormsProvider::list() and via the dedicated
 * /api/objects/{r}/{s}/{id}/forms controller routes.
 *
 * The service caches form metadata at link-time (title, status,
 * form_hash, expires_at) so the sidebar/widget surfaces still render
 * a useful row even when:
 *  - NC Forms is uninstalled (graceful degradation, AD-23);
 *  - the underlying form has been archived/deleted in NC Forms.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Db\FormSubmissionRef;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * FormLinkService manages form-to-object links via a dedicated link table.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class FormLinkService implements FormLinkServiceInterface {

	/**
	 * NC Forms app id.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'forms';

	/**
	 * NC Forms classes, late-bound: they are only on the classpath when
	 * `forms` is installed, so a `use` import would crash at class-load
	 * time on instances without Forms.
	 *
	 * @var string
	 */
	private const FORM_CLASS = '\\OCA\\Forms\\Db\\Form';

	/**
	 * NC Forms' FormMapper class (late-bound).
	 *
	 * @var string
	 */
	private const FORM_MAPPER_CLASS = '\\OCA\\Forms\\Db\\FormMapper';

	/**
	 * NC Forms' FormsService class (late-bound).
	 *
	 * @var string
	 */
	private const FORMS_SERVICE_CLASS = '\\OCA\\Forms\\Service\\FormsService';

	/**
	 * Link-time metadata when NC Forms or the form row is unavailable.
	 *
	 * @var array{title:null, hash:null, status:null, expiresAt:null}
	 */
	private const NO_METADATA = [
		'title' => null,
		'hash' => null,
		'status' => null,
		'expiresAt' => null,
	];

	/**
	 * The parent row synthesised for a submission whose form is not linked itself.
	 *
	 * @var array<string, mixed>
	 */
	private const SYNTHETIC_PARENT = [
		'id' => null,
		'objectUuid' => null,
		'registerId' => null,
		'schemaId' => null,
		'formId' => null,
		'formHash' => null,
		'submissionId' => null,
		'title' => null,
		'status' => null,
		'expiresAt' => null,
		'linkedBy' => null,
		'linkedAt' => null,
		'submissions' => [],
		'synthetic' => true,
	];

	/**
	 * Form-link mapper.
	 *
	 * @var FormLinkMapper
	 */
	private readonly FormLinkMapper $formLinkMapper;

	/**
	 * Container (used for late-bound `OCA\Forms\*` classes that only
	 * exist on the classpath when NC Forms is installed).
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * NC app manager — used to gate `OCA\Forms` access.
	 *
	 * @var IAppManager
	 */
	private readonly IAppManager $appManager;

	/**
	 * DB connection — used for the available-forms query when
	 * FormMapper isn't on the classpath but the table exists.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * User session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param FormLinkMapper $formLinkMapper Form-link mapper.
	 * @param ContainerInterface $container DI container for late-bound NC Forms classes.
	 * @param IAppManager $appManager NC app manager.
	 * @param IDBConnection $db DB connection.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		FormLinkMapper $formLinkMapper,
		ContainerInterface $container,
		IAppManager $appManager,
		IDBConnection $db,
		IUserSession $userSession,
		LoggerInterface $logger,
	) {
		$this->formLinkMapper = $formLinkMapper;
		$this->container = $container;
		$this->appManager = $appManager;
		$this->db = $db;
		$this->userSession = $userSession;
		$this->logger = $logger;

	}//end __construct()

	/**
	 * Return the form links for an object grouped by parent form.
	 *
	 * Shape:
	 *   [
	 *     { ...formLinkRow, submissions: [{ ...submissionLinkRow }, ...] },
	 *     ...
	 *   ]
	 *
	 * Submission-level links whose form has no form-level link row are
	 * still surfaced under a synthetic parent (so a submission can be
	 * linked without forcing the form itself to be linked).
	 *
	 * @param string $objectUuid The OR object UUID.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the linked-forms listing contract is owned by the integration-forms capability.
	 */
	public function getLinkedForms(string $objectUuid): array {
		$byForm      = [];
		$submissions = [];
		foreach ($this->formLinkMapper->findByObjectUuid($objectUuid) as $link) {
			if ($link->getSubmissionId() !== null) {
				$submissions[] = $link->jsonSerialize();
				continue;
			}

			$byForm[$link->getFormId()] = $link->jsonSerialize() + ['submissions' => []];
		}

		return $this->nestSubmissions(byForm: $byForm, submissions: $submissions);
	}//end getLinkedForms()

	/**
	 * Nest submission rows under their form row, synthesising a parent when the form itself is not linked.
	 *
	 * @param array<int, array<string, mixed>> $byForm      Form-level rows keyed by form id.
	 * @param list<array<string, mixed>>       $submissions Submission-level rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function nestSubmissions(array $byForm, array $submissions): array {
		foreach ($submissions as $sub) {
			$formId = $sub['formId'];
			// Synthesize a minimal parent row so the submission has a home in the response
			// even when only the submission was explicitly linked.
			$byForm[$formId] ??= $this->syntheticParent(submission: $sub);
			$byForm[$formId]['submissions'][] = $sub;
		}

		return array_values($byForm);
	}//end nestSubmissions()

	/**
	 * The parent row shown for a submission whose form has no form-level link.
	 *
	 * @param array<string, mixed> $submission The serialised submission link.
	 *
	 * @return array<string, mixed>
	 */
	private function syntheticParent(array $submission): array {
		return array_replace(
			self::SYNTHETIC_PARENT,
			[
				'objectUuid' => $submission['objectUuid'],
				'registerId' => $submission['registerId'],
				'schemaId' => $submission['schemaId'] ?? null,
				'formId' => $submission['formId'],
				'formHash' => $submission['formHash'] ?? null,
				'title' => $submission['title'] ?? null,
			]
		);
	}//end syntheticParent()

	/**
	 * Link an existing NC Forms form to an OR object.
	 *
	 * Idempotent: if a form-level link already exists for the
	 * `(objectUuid, formId)` pair, the existing row is returned (no
	 * duplicate is inserted, no exception is raised).
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $registerId The OR register id.
	 * @param integer $formId The NC Forms form id.
	 * @param integer $schemaId Optional OR schema id.
	 *
	 * @return FormLink The created (or existing) link row.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the form-link contract is owned by the integration-forms capability.
	 */
	public function linkForm(
		string $objectUuid,
		int $registerId,
		int $formId,
		?int $schemaId = null,
	): FormLink {
		$uid = $this->requireUid();

		$existing = $this->formLinkMapper->findFormLink($objectUuid, $formId);
		if ($existing !== null) {
			return $existing;
		}

		$link = $this->newLink(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId);
		$link->setSubmissionId(null);
		return $this->insertLink(link: $link, formId: $formId, uid: $uid);
	}//end linkForm()

	/**
	 * Link a specific NC Forms submission to an OR object.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $registerId The OR register id.
	 * @param integer $formId The parent form id.
	 * @param integer $submissionId The submission id.
	 * @param integer $schemaId Optional OR schema id.
	 *
	 * @return FormLink The created (or existing) link row.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the submission-link contract is owned by the integration-forms capability.
	 */
	public function linkFormSubmission(string $objectUuid, int $registerId, int $formId, int $submissionId, ?int $schemaId = null): FormLink {
		$uid = $this->requireUid();

		$submission = new FormSubmissionRef(formId: $formId, submissionId: $submissionId);
		$existing   = $this->formLinkMapper->findSubmissionLink(objectUuid: $objectUuid, submission: $submission);
		if ($existing !== null) {
			return $existing;
		}

		$link = $this->newLink(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId);
		$link->setSubmissionId($submissionId);
		return $this->insertLink(link: $link, formId: $formId, uid: $uid);
	}//end linkFormSubmission()

	/**
	 * Active session UID, or throw if no user is logged in.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception When there is no active user.
	 */
	private function requireUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in', 401);
		}

		return $user->getUID();
	}//end requireUid()

	/**
	 * A new link row for an object.
	 *
	 * @param string       $objectUuid The OR object UUID.
	 * @param integer      $registerId The OR register id.
	 * @param integer|null $schemaId   The OR schema id.
	 *
	 * @return FormLink
	 */
	private function newLink(string $objectUuid, int $registerId, ?int $schemaId): FormLink {
		$link = new FormLink();
		$link->setObjectUuid($objectUuid);
		$link->setRegisterId($registerId);
		$link->setSchemaId($schemaId);
		return $link;
	}//end newLink()

	/**
	 * Stamp a link with its form, the form's metadata and the linking user, and insert it.
	 *
	 * @param FormLink $link   The new link row.
	 * @param integer  $formId The NC Forms form id.
	 * @param string   $uid    The linking user.
	 *
	 * @return FormLink The inserted row.
	 */
	private function insertLink(FormLink $link, int $formId, string $uid): FormLink {
		$meta = $this->snapshotFormMetadata(formId: $formId);
		$link->setFormId($formId);
		$link->setFormHash($meta['hash']);
		$link->setTitle($meta['title']);
		$link->setStatus($meta['status']);
		$link->setExpiresAt($meta['expiresAt']);
		$link->setLinkedBy($uid);
		$link->setLinkedAt(new DateTime());
		return $this->formLinkMapper->insert($link);
	}//end insertLink()

	/**
	 * Unlink a form (and every submission-level row beneath it) from an object.
	 *
	 * Idempotent: returns 0 when no rows match. Submission-level rows
	 * are removed too — that's the surface contract: "unlink the form"
	 * means "drop every link to this form on this object".
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $formId The NC Forms form id.
	 *
	 * @return integer Number of rows removed.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-form contract is owned by the integration-forms capability.
	 */
	public function unlinkForm(string $objectUuid, int $formId): int {
		return $this->formLinkMapper->deleteByObjectAndForm($objectUuid, $formId);
	}//end unlinkForm()

	/**
	 * Unlink a single submission from an object.
	 *
	 * Form-level row (if any) is left untouched.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $formId The parent form id.
	 * @param integer $submissionId The submission id.
	 *
	 * @return boolean True when a row was removed, false when none existed.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-submission contract is owned by the integration-forms capability.
	 */
	public function unlinkSubmission(string $objectUuid, int $formId, int $submissionId): bool {
		$link = $this->formLinkMapper->findSubmissionLink(
			objectUuid: $objectUuid,
			submission: new FormSubmissionRef(formId: $formId, submissionId: $submissionId)
		);
		if ($link === null) {
			return false;
		}

		$this->formLinkMapper->delete($link);
		return true;
	}//end unlinkSubmission()

	/**
	 * Create a new NC Forms form via the FormMapper and immediately
	 * link it to an OR object.
	 *
	 * The form is created with the same default shape that NC Forms'
	 * own `ApiController::newForm()` uses (empty title/description,
	 * private access, `isAnonymous=false`, `expires=0`). The caller
	 * is expected to follow up by editing the form in NC Forms; the
	 * link points at the form regardless.
	 *
	 * If the Forms app isn't installed (`OCA\Forms\Db\Form` /
	 * `FormMapper` not on the classpath), an exception is thrown so
	 * the caller can return a clean 503/501.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $registerId The OR register id.
	 * @param string $title Initial form title.
	 * @param string $description Initial form description.
	 * @param integer $schemaId Optional OR schema id.
	 *
	 * @return FormLink The link row pointing at the freshly-created form.
	 *
	 * @throws Exception When the Forms app is missing or the create fails.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link contract is owned by the integration-forms capability.
	 */
	public function createAndLinkForm(
		string $objectUuid,
		int $registerId,
		string $title,
		string $description = '',
		?int $schemaId = null,
	): FormLink {
		$uid      = $this->requireUid();
		$services = $this->resolveFormsServices();
		// Mirror NC Forms' own ApiController::newForm shape.
		$form = $this->draftForm(services: $services, ownerId: $uid, title: $title, description: $description);
		$services['mapper']->insert($form);

		return $this->linkForm(objectUuid: $objectUuid, registerId: $registerId, formId: (int)$form->getId(), schemaId: $schemaId);
	}//end createAndLinkForm()

	/**
	 * NC Forms' FormMapper and FormsService plus a new Form entity, or an exception when Forms cannot be reached.
	 *
	 * @return array{mapper: mixed, service: mixed, form: object}
	 *
	 * @throws Exception When the Forms app or its classes are missing, or its services cannot be resolved.
	 */
	private function resolveFormsServices(): array {
		if ($this->appManager->isInstalled(self::REQUIRED_APP) === false) {
			throw new Exception('NC Forms app is not installed', 503);
		}

		$formClass = self::FORM_CLASS;
		if (class_exists($formClass) === false || class_exists(self::FORM_MAPPER_CLASS) === false) {
			throw new Exception('NC Forms classes not available', 503);
		}

		return $this->formsContainerServices() + ['form' => new $formClass()];
	}//end resolveFormsServices()

	/**
	 * NC Forms' FormMapper and FormsService from the container.
	 *
	 * @return array{mapper: mixed, service: mixed}
	 *
	 * @throws Exception When the services cannot be resolved.
	 */
	private function formsContainerServices(): array {
		try {
			return [
				'mapper' => $this->container->get(self::FORM_MAPPER_CLASS),
				'service' => $this->container->get(self::FORMS_SERVICE_CLASS),
			];
		} catch (Throwable $e) {
			$this->logger->error('Failed to resolve NC Forms services: ' . $e->getMessage());
			throw new Exception('Could not bootstrap NC Forms services', 503);
		}
	}//end formsContainerServices()

	/**
	 * Shape the new NC Forms form as private, non-anonymous and without expiry.
	 *
	 * @param array{mapper: mixed, service: mixed, form: object} $services    The NC Forms services and new form.
	 * @param string                                             $ownerId     The owner uid.
	 * @param string                                             $title       The title.
	 * @param string                                             $description The description.
	 *
	 * @return object The unsaved NC Forms Form entity.
	 */
	private function draftForm(array $services, string $ownerId, string $title, string $description): object {
		$form = $services['form'];
		$form->setOwnerId($ownerId);
		$form->setHash($services['service']->generateFormHash());
		$form->setTitle($title);
		$form->setDescription($description);
		$form->setAccess(['permitAllUsers' => false, 'showToAllUsers' => false]);
		$form->setSubmitMultiple(false);
		$form->setAllowEditSubmissions(false);
		$form->setShowExpiration(false);
		$form->setExpires(0);
		$form->setIsAnonymous(false);
		return $form;
	}//end draftForm()

	/**
	 * Return the set of NC Forms forms the current user owns so the
	 * picker UI can render a list to choose from.
	 *
	 * Defensive: if Forms isn't installed or the query blows up, an
	 * empty array is returned (AD-23). Each row carries `id`, `hash`,
	 * `title`, `description`, plus a `linked` boolean signalling
	 * whether the form already has a form-level link to the given
	 * `$objectUuid` (so the picker can grey-out already-linked rows).
	 *
	 * @param string|null $objectUuid Optional object UUID to compute
	 *                                the `linked` flag against.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the picker-source contract is owned by the integration-forms capability.
	 */
	public function getAvailableForms(?string $objectUuid = null): array {
		$uid = $this->formsUserId();
		if ($uid === null) {
			return [];
		}

		$rows = $this->ownedFormRows(uid: $uid);
		if ($rows === null) {
			return [];
		}

		$linkedSet = $this->linkedFormIds(objectUuid: $objectUuid);
		return array_map(
			fn (array $row): array => $this->pickerRow(row: $row, linkedSet: $linkedSet),
			$rows
		);
	}//end getAvailableForms()

	/**
	 * The current user's uid when NC Forms is installed, else null.
	 *
	 * @return string|null
	 */
	private function formsUserId(): ?string {
		if ($this->appManager->isInstalled(self::REQUIRED_APP) === false) {
			return null;
		}

		return $this->userSession->getUser()?->getUID();
	}//end formsUserId()

	/**
	 * The NC Forms forms a user owns, newest first, or null when the query fails.
	 *
	 * @param string $uid The owner uid.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function ownedFormRows(string $uid): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'hash', 'title', 'description', 'expires', 'state')
				->from('forms_v2_forms')
				->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($uid)))
				->orderBy('last_updated', 'DESC');
			return $this->fetchRows(result: $qb->executeQuery());
		} catch (Throwable $e) {
			$this->logger->debug('Available-forms query failed: ' . $e->getMessage());
			return null;
		}
	}//end ownedFormRows()

	/**
	 * Every row of a query result.
	 *
	 * @param IResult $result The result.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fetchRows(IResult $result): array {
		$rows = [];
		$row  = $result->fetch();
		while ($row !== false) {
			$rows[] = $row;
			$row    = $result->fetch();
		}

		return $rows;
	}//end fetchRows()

	/**
	 * The form ids an object has a form-level link to, as a set.
	 *
	 * @param string|null $objectUuid The object UUID, or null for none.
	 *
	 * @return array<int, true>
	 */
	private function linkedFormIds(?string $objectUuid): array {
		$linkedSet = [];
		if ($objectUuid === null) {
			return $linkedSet;
		}

		foreach ($this->formLinkMapper->findByObjectUuid($objectUuid) as $existing) {
			if ($existing->getSubmissionId() === null) {
				$linkedSet[(int)$existing->getFormId()] = true;
			}
		}

		return $linkedSet;
	}//end linkedFormIds()

	/**
	 * The picker row of an NC Forms form.
	 *
	 * @param array<string, mixed> $row       The NC Forms row.
	 * @param array<int, true>     $linkedSet The form ids already linked to the object.
	 *
	 * @return array<string, mixed>
	 */
	private function pickerRow(array $row, array $linkedSet): array {
		$formId  = (int)($row['id'] ?? 0);
		$expires = (int)($row['expires'] ?? 0);
		return [
			'id' => $formId,
			'hash' => (string)($row['hash'] ?? ''),
			'title' => (string)($row['title'] ?? ''),
			'description' => (string)($row['description'] ?? ''),
			'status' => $this->stateToStatus(state: (int)($row['state'] ?? 0), expires: $expires),
			'expiresAt' => $this->atomExpiry(expires: $expires),
			'submissionCount' => $this->countSubmissions(formId: $formId),
			'linked' => isset($linkedSet[$formId]),
		];
	}//end pickerRow()

	/**
	 * An expiry epoch as an ATOM date, or null when the form does not expire.
	 *
	 * @param integer $expires The epoch, 0 for none.
	 *
	 * @return string|null
	 */
	private function atomExpiry(int $expires): ?string {
		if ($expires > 0) {
			return gmdate(DATE_ATOM, $expires);
		}

		return null;
	}//end atomExpiry()

	/**
	 * Snapshot form metadata (title / status / hash / expiresAt) from
	 * the NC Forms tables at link-time. Defensive: returns nulls when
	 * the Forms app isn't installed or the row doesn't exist.
	 *
	 * @param integer $formId The NC Forms form id.
	 *
	 * @return array{title:?string, hash:?string, status:?string, expiresAt:?DateTime}
	 */
	private function snapshotFormMetadata(int $formId): array {
		if ($this->appManager->isInstalled(self::REQUIRED_APP) === false) {
			return self::NO_METADATA;
		}

		try {
			$row = $this->fetchFormRow(formId: $formId);
			if ($row === false) {
				return self::NO_METADATA;
			}

			return $this->metadataFromRow(row: $row);
		} catch (Throwable $e) {
			$this->logger->debug('Form metadata snapshot failed: ' . $e->getMessage());
			return self::NO_METADATA;
		}
	}//end snapshotFormMetadata()

	/**
	 * The NC Forms row of a form, or false when there is none.
	 *
	 * @param integer $formId The NC Forms form id.
	 *
	 * @return array<string, mixed>|false
	 */
	private function fetchFormRow(int $formId): array|false {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'hash', 'title', 'expires', 'state')
			->from('forms_v2_forms')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId)));
		$result = $qb->executeQuery();
		$row    = $result->fetch();
		$result->closeCursor();
		return $row;
	}//end fetchFormRow()

	/**
	 * The link-time metadata of an NC Forms row.
	 *
	 * @param array<string, mixed> $row The NC Forms row.
	 *
	 * @return array{title:?string, hash:?string, status:?string, expiresAt:?DateTime}
	 */
	private function metadataFromRow(array $row): array {
		$expires   = (int)($row['expires'] ?? 0);
		$expiresAt = null;
		if ($expires > 0) {
			$expiresAt = (new DateTime())->setTimestamp($expires);
		}

		return [
			'title' => (string)($row['title'] ?? ''),
			'hash' => (string)($row['hash'] ?? ''),
			'status' => $this->stateToStatus(state: (int)($row['state'] ?? 0), expires: $expires),
			'expiresAt' => $expiresAt,
		];
	}//end metadataFromRow()

	/**
	 * Map NC Forms' numeric `state` column + expiry timestamp onto
	 * the canonical `open` / `closed` / `draft` / `archived` strings
	 * used by the registry leaf row contract.
	 *
	 * NC Forms `state` values:
	 *   0 -> active (open)
	 *   1 -> archived (closed)
	 *   2 -> draft
	 *
	 * `expires > now()` also forces `closed`.
	 *
	 * @param integer $state NC Forms `state` column.
	 * @param integer $expires NC Forms `expires` epoch (0 = no expiry).
	 *
	 * @return string Canonical status.
	 */
	private function stateToStatus(int $state, int $expires): string {
		if ($state === 1) {
			return 'archived';
		}

		if ($state === 2) {
			return 'draft';
		}

		if ($expires > 0 && $expires <= time()) {
			return 'closed';
		}

		return 'open';
	}//end stateToStatus()

	/**
	 * Best-effort submission count for a given form id. Used by the
	 * available-forms picker so the row can show "12 submissions".
	 *
	 * Defensive: returns 0 when the query fails.
	 *
	 * @param integer $formId The NC Forms form id.
	 *
	 * @return integer
	 */
	private function countSubmissions(int $formId): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->createFunction('COUNT(*)'))
				->from('forms_v2_submissions')
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			return $count;
		} catch (Throwable $e) {
			return 0;
		}

	}//end countSubmissions()
}//end class
