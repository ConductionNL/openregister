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
use OCA\OpenRegister\Db\FormLinkMapperInterface;
use OCA\OpenRegister\Db\FormSubmissionRef;
use OCP\DB\IResult;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
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
	 * Constructor.
	 *
	 * @param FormLinkMapperInterface $formLinkMapper Form-link mapper.
	 * @param ContainerInterface      $container      DI container for late-bound NC Forms classes.
	 * @param IDBConnection           $db             DB connection.
	 * @param LinkServiceRuntime      $runtime        App manager, user session and logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FormLinkMapperInterface $formLinkMapper,
		private readonly ContainerInterface $container,
		private readonly IDBConnection $db,
		private readonly LinkServiceRuntime $runtime,
	) {
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
	 * @param FormLinkScope $scope  The object, register and schema.
	 * @param integer       $formId The NC Forms form id.
	 *
	 * @return FormLink The created (or existing) link row.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the form-link contract is owned by the integration-forms capability.
	 */
	public function linkFormTo(FormLinkScope $scope, int $formId): FormLink {
		$uid = $this->requireUid();

		$existing = $this->formLinkMapper->findFormLink($scope->objectUuid, $formId);
		if ($existing !== null) {
			return $existing;
		}

		$link = $scope->newLink(uid: $uid);
		$link->setSubmissionId(null);
		return $this->insertLink(link: $link, formId: $formId);
	}//end linkFormTo()

	/**
	 * Link a specific NC Forms submission to an OR object, returning the existing submission link when there is one.
	 *
	 * @param FormLinkScope     $scope      The object, register and schema.
	 * @param FormSubmissionRef $submission The form and submission ids.
	 *
	 * @return FormLink The created (or existing) link row.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the submission-link contract is owned by the integration-forms capability.
	 */
	public function linkSubmissionTo(FormLinkScope $scope, FormSubmissionRef $submission): FormLink {
		$uid = $this->requireUid();

		$existing = $this->formLinkMapper->findSubmissionLink(objectUuid: $scope->objectUuid, submission: $submission);
		if ($existing !== null) {
			return $existing;
		}

		$link = $scope->newLink(uid: $uid);
		$link->setSubmissionId($submission->submissionId);
		return $this->insertLink(link: $link, formId: $submission->formId);
	}//end linkSubmissionTo()

	/**
	 * Active session UID, or throw if no user is logged in.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception When there is no active user.
	 */
	private function requireUid(): string {
		$user = $this->runtime->userSession()->getUser();
		if ($user === null) {
			throw new Exception('No user logged in', 401);
		}

		return $user->getUID();
	}//end requireUid()

	/**
	 * Stamp a link with its form and the form's metadata, and insert it.
	 *
	 * @param FormLink $link   The new link row, already carrying the linking user.
	 * @param integer  $formId The NC Forms form id.
	 *
	 * @return FormLink The inserted row.
	 */
	private function insertLink(FormLink $link, int $formId): FormLink {
		$meta = $this->snapshotFormMetadata(formId: $formId);
		$link->setFormId($formId);
		$link->setFormHash($meta['hash']);
		$link->setTitle($meta['title']);
		$link->setStatus($meta['status']);
		$link->setExpiresAt($meta['expiresAt']);
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
	 * @param string            $objectUuid The OR object UUID.
	 * @param FormSubmissionRef $submission The parent form and the submission ids.
	 *
	 * @return boolean True when a row was removed, false when none existed.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-submission contract is owned by the integration-forms capability.
	 */
	public function unlinkSubmission(string $objectUuid, FormSubmissionRef $submission): bool {
		$link = $this->formLinkMapper->findSubmissionLink(objectUuid: $objectUuid, submission: $submission);
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
	 * @param FormLinkScope $scope The object, register and schema.
	 * @param NewForm       $form  The initial title and description.
	 *
	 * @return FormLink The link row pointing at the freshly-created form.
	 *
	 * @throws Exception When the Forms app is missing or the create fails.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link contract is owned by the integration-forms capability.
	 */
	public function createAndLinkForm(FormLinkScope $scope, NewForm $form): FormLink {
		$uid      = $this->requireUid();
		$services = $this->resolveFormsServices();
		// Mirror NC Forms' own ApiController::newForm shape.
		$fields = ['ownerId' => $uid, 'title' => $form->title, 'description' => $form->description];
		$entity = $this->draftForm(services: $services, fields: $fields);
		$services['mapper']->insert($entity);

		return $this->linkFormTo(scope: $scope, formId: (int)$entity->getId());
	}//end createAndLinkForm()

	/**
	 * NC Forms' FormMapper and FormsService plus a new Form entity, or an exception when Forms cannot be reached.
	 *
	 * @return array{mapper: mixed, service: mixed, form: object}
	 *
	 * @throws Exception When the Forms app or its classes are missing, or its services cannot be resolved.
	 */
	private function resolveFormsServices(): array {
		if ($this->runtime->appManager()->isInstalled(self::REQUIRED_APP) === false) {
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
			$this->runtime->logger()->error('Failed to resolve NC Forms services: ' . $e->getMessage());
			throw new Exception('Could not bootstrap NC Forms services', 503);
		}
	}//end formsContainerServices()

	/**
	 * Shape the new NC Forms form as private, non-anonymous and without expiry.
	 *
	 * @param array{mapper: mixed, service: mixed, form: object}       $services The NC Forms services and new form.
	 * @param array{ownerId:string, title:string, description:string} $fields   The owner uid, title and description.
	 *
	 * @return object The unsaved NC Forms Form entity.
	 */
	private function draftForm(array $services, array $fields): object {
		$form = $services['form'];
		$form->setOwnerId($fields['ownerId']);
		$form->setHash($services['service']->generateFormHash());
		$form->setTitle($fields['title']);
		$form->setDescription($fields['description']);
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
		if ($this->runtime->appManager()->isInstalled(self::REQUIRED_APP) === false) {
			return null;
		}

		return $this->runtime->userSession()->getUser()?->getUID();
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
			$this->runtime->logger()->debug('Available-forms query failed: ' . $e->getMessage());
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
			'status' => FormsAppRows::status(state: (int)($row['state'] ?? 0), expires: $expires),
			'expiresAt' => $this->atomExpiry(expires: $expires),
			'submissionCount' => FormsAppRows::submissionCount(db: $this->db, formId: $formId),
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
		if ($this->runtime->appManager()->isInstalled(self::REQUIRED_APP) === false) {
			return self::NO_METADATA;
		}

		try {
			$row = $this->fetchFormRow(formId: $formId);
			if ($row === false) {
				return self::NO_METADATA;
			}

			return $this->metadataFromRow(row: $row);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Form metadata snapshot failed: ' . $e->getMessage());
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
			'status' => FormsAppRows::status(state: (int)($row['state'] ?? 0), expires: $expires),
			'expiresAt' => $expiresAt,
		];
	}//end metadataFromRow()
}//end class
