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
use OCP\App\IAppManager;
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
class FormLinkService
{

    /**
     * NC Forms app id.
     *
     * @var string
     */
    private const REQUIRED_APP = 'forms';

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
     * @param FormLinkMapper     $formLinkMapper Form-link mapper.
     * @param ContainerInterface $container      DI container for late-bound NC Forms classes.
     * @param IAppManager        $appManager     NC app manager.
     * @param IDBConnection      $db             DB connection.
     * @param IUserSession       $userSession    User session.
     * @param LoggerInterface    $logger         Logger.
     *
     * @return void
     */
    public function __construct(
        FormLinkMapper $formLinkMapper,
        ContainerInterface $container,
        IAppManager $appManager,
        IDBConnection $db,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->formLinkMapper = $formLinkMapper;
        $this->container      = $container;
        $this->appManager     = $appManager;
        $this->db          = $db;
        $this->userSession = $userSession;
        $this->logger      = $logger;

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
    public function getLinkedForms(string $objectUuid): array
    {
        $links = $this->formLinkMapper->findByObjectUuid($objectUuid);

        $byForm      = [];
        $submissions = [];

        foreach ($links as $link) {
            $row = $link->jsonSerialize();
            if ($link->getSubmissionId() !== null) {
                $submissions[] = $row;
                continue;
            }

            $row['submissions']         = [];
            $byForm[$link->getFormId()] = $row;
        }

        foreach ($submissions as $sub) {
            $formId = $sub['formId'];
            if (isset($byForm[$formId]) === false) {
                // Synthesize a minimal parent row so the submission has a
                // home in the response even when only the submission was
                // explicitly linked.
                $byForm[$formId] = [
                    'id'           => null,
                    'objectUuid'   => $sub['objectUuid'],
                    'registerId'   => $sub['registerId'],
                    'schemaId'     => $sub['schemaId'] ?? null,
                    'formId'       => $formId,
                    'formHash'     => $sub['formHash'] ?? null,
                    'submissionId' => null,
                    'title'        => $sub['title'] ?? null,
                    'status'       => null,
                    'expiresAt'    => null,
                    'linkedBy'     => null,
                    'linkedAt'     => null,
                    'submissions'  => [],
                    'synthetic'    => true,
                ];
            }//end if

            $byForm[$formId]['submissions'][] = $sub;
        }//end foreach

        return array_values($byForm);

    }//end getLinkedForms()

    /**
     * Link an existing NC Forms form to an OR object.
     *
     * Idempotent: if a form-level link already exists for the
     * `(objectUuid, formId)` pair, the existing row is returned (no
     * duplicate is inserted, no exception is raised).
     *
     * @param string  $objectUuid The OR object UUID.
     * @param integer $registerId The OR register id.
     * @param integer $formId     The NC Forms form id.
     * @param integer $schemaId   Optional OR schema id.
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
        ?int $schemaId=null
    ): FormLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        $existing = $this->formLinkMapper->findFormLink($objectUuid, $formId);
        if ($existing !== null) {
            return $existing;
        }

        $meta = $this->snapshotFormMetadata(formId: $formId);

        $link = new FormLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setFormId($formId);
        $link->setFormHash($meta['hash']);
        $link->setSubmissionId(null);
        $link->setTitle($meta['title']);
        $link->setStatus($meta['status']);
        $link->setExpiresAt($meta['expiresAt']);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->formLinkMapper->insert($link);

    }//end linkForm()

    /**
     * Link a specific NC Forms submission to an OR object.
     *
     * @param string  $objectUuid   The OR object UUID.
     * @param integer $registerId   The OR register id.
     * @param integer $formId       The parent form id.
     * @param integer $submissionId The submission id.
     * @param integer $schemaId     Optional OR schema id.
     *
     * @return FormLink The created (or existing) link row.
     *
     * @throws Exception If no user is logged in.
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the submission-link contract is owned by the integration-forms capability.
     */
    public function linkFormSubmission(
        string $objectUuid,
        int $registerId,
        int $formId,
        int $submissionId,
        ?int $schemaId=null
    ): FormLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        $existing = $this->formLinkMapper->findSubmissionLink($objectUuid, $formId, $submissionId);
        if ($existing !== null) {
            return $existing;
        }

        $meta = $this->snapshotFormMetadata(formId: $formId);

        $link = new FormLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setFormId($formId);
        $link->setFormHash($meta['hash']);
        $link->setSubmissionId($submissionId);
        $link->setTitle($meta['title']);
        $link->setStatus($meta['status']);
        $link->setExpiresAt($meta['expiresAt']);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->formLinkMapper->insert($link);

    }//end linkFormSubmission()

    /**
     * Unlink a form (and every submission-level row beneath it) from an object.
     *
     * Idempotent: returns 0 when no rows match. Submission-level rows
     * are removed too — that's the surface contract: "unlink the form"
     * means "drop every link to this form on this object".
     *
     * @param string  $objectUuid The OR object UUID.
     * @param integer $formId     The NC Forms form id.
     *
     * @return integer Number of rows removed.
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-form contract is owned by the integration-forms capability.
     */
    public function unlinkForm(string $objectUuid, int $formId): int
    {
        return $this->formLinkMapper->deleteByObjectAndForm($objectUuid, $formId);

    }//end unlinkForm()

    /**
     * Unlink a single submission from an object.
     *
     * Form-level row (if any) is left untouched.
     *
     * @param string  $objectUuid   The OR object UUID.
     * @param integer $formId       The parent form id.
     * @param integer $submissionId The submission id.
     *
     * @return boolean True when a row was removed, false when none existed.
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-submission contract is owned by the integration-forms capability.
     */
    public function unlinkSubmission(string $objectUuid, int $formId, int $submissionId): bool
    {
        $link = $this->formLinkMapper->findSubmissionLink($objectUuid, $formId, $submissionId);
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
     * @param string  $objectUuid  The OR object UUID.
     * @param integer $registerId  The OR register id.
     * @param string  $title       Initial form title.
     * @param string  $description Initial form description.
     * @param integer $schemaId    Optional OR schema id.
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
        string $description='',
        ?int $schemaId=null
    ): FormLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        if ($this->appManager->isInstalled(self::REQUIRED_APP) === false) {
            throw new Exception('NC Forms app is not installed', 503);
        }

        // Late-bind NC Forms classes — they're only on the classpath
        // when `forms` is installed, so a `use` import here would crash
        // at class-load time on instances without Forms.
        $formClass    = '\\OCA\\Forms\\Db\\Form';
        $mapperClass  = '\\OCA\\Forms\\Db\\FormMapper';
        $serviceClass = '\\OCA\\Forms\\Service\\FormsService';

        if (class_exists($formClass) === false || class_exists($mapperClass) === false) {
            throw new Exception('NC Forms classes not available', 503);
        }

        try {
            // FormMapper from NC Forms (late-bound).
            $formMapper = $this->container->get($mapperClass);

            // FormsService from NC Forms (late-bound).
            $formsService = $this->container->get($serviceClass);
        } catch (Throwable $e) {
            $this->logger->error('Failed to resolve NC Forms services: '.$e->getMessage());
            throw new Exception('Could not bootstrap NC Forms services', 503);
        }

        // Mirror NC Forms' own ApiController::newForm shape.
        $form = new $formClass();
        $form->setOwnerId($user->getUID());
        $form->setHash($formsService->generateFormHash());
        $form->setTitle($title);
        $form->setDescription($description);
        $form->setAccess(
            [
                'permitAllUsers' => false,
                'showToAllUsers' => false,
            ]
        );
        $form->setSubmitMultiple(false);
        $form->setAllowEditSubmissions(false);
        $form->setShowExpiration(false);
        $form->setExpires(0);
        $form->setIsAnonymous(false);

        $formMapper->insert($form);

        $formId = (int) $form->getId();

        return $this->linkForm(
            objectUuid: $objectUuid,
            registerId: $registerId,
            formId: $formId,
            schemaId: $schemaId
        );

    }//end createAndLinkForm()

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
    public function getAvailableForms(?string $objectUuid=null): array
    {
        if ($this->appManager->isInstalled(self::REQUIRED_APP) === false) {
            return [];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'hash', 'title', 'description', 'expires', 'state')
                ->from('forms_v2_forms')
                ->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($user->getUID())))
                ->orderBy('last_updated', 'DESC');

            $result = $qb->executeQuery();
            $rows   = [];
            $row    = $result->fetch();
            while ($row !== false) {
                $rows[] = $row;
                $row    = $result->fetch();
            }
        } catch (Throwable $e) {
            $this->logger->debug('Available-forms query failed: '.$e->getMessage());
            return [];
        }//end try

        $linkedSet = [];
        if ($objectUuid !== null) {
            foreach ($this->formLinkMapper->findByObjectUuid($objectUuid) as $existing) {
                if ($existing->getSubmissionId() === null) {
                    $linkedSet[(int) $existing->getFormId()] = true;
                }
            }
        }

        return array_map(
            function (array $row) use ($linkedSet): array {
                $formId    = (int) ($row['id'] ?? 0);
                $expires   = (int) ($row['expires'] ?? 0);
                $state     = (int) ($row['state'] ?? 0);
                $expiresAt = null;
                if ($expires > 0) {
                    $expiresAt = gmdate(DATE_ATOM, $expires);
                }

                return [
                    'id'              => $formId,
                    'hash'            => (string) ($row['hash'] ?? ''),
                    'title'           => (string) ($row['title'] ?? ''),
                    'description'     => (string) ($row['description'] ?? ''),
                    'status'          => $this->stateToStatus(state: $state, expires: $expires),
                    'expiresAt'       => $expiresAt,
                    'submissionCount' => $this->countSubmissions(formId: $formId),
                    'linked'          => isset($linkedSet[$formId]),
                ];
            },
            $rows
        );

    }//end getAvailableForms()

    /**
     * Resolve the link rows for a (object, form) pair into a single
     * combined record carrying the form-level link (if any) and its
     * submission-level children. Used by the controller's index path
     * when the caller wants one form's view (rather than the full list).
     *
     * @param string  $objectUuid The OR object UUID.
     * @param integer $formId     The NC Forms form id.
     *
     * @return array<string,mixed>|null
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the single-form view contract is owned by the integration-forms capability.
     */
    public function getLinkedForm(string $objectUuid, int $formId): ?array
    {
        $allForms = $this->getLinkedForms(objectUuid: $objectUuid);
        foreach ($allForms as $form) {
            if ((int) $form['formId'] === $formId) {
                return $form;
            }
        }

        return null;

    }//end getLinkedForm()

    /**
     * Snapshot form metadata (title / status / hash / expiresAt) from
     * the NC Forms tables at link-time. Defensive: returns nulls when
     * the Forms app isn't installed or the row doesn't exist.
     *
     * @param integer $formId The NC Forms form id.
     *
     * @return array{title:?string, hash:?string, status:?string, expiresAt:?DateTime}
     */
    private function snapshotFormMetadata(int $formId): array
    {
        $defaults = [
            'title'     => null,
            'hash'      => null,
            'status'    => null,
            'expiresAt' => null,
        ];

        if ($this->appManager->isInstalled(self::REQUIRED_APP) === false) {
            return $defaults;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'hash', 'title', 'expires', 'state')
                ->from('forms_v2_forms')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($formId)));
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row === false) {
                return $defaults;
            }

            $expires   = (int) ($row['expires'] ?? 0);
            $state     = (int) ($row['state'] ?? 0);
            $expiresAt = null;
            if ($expires > 0) {
                $expiresAt = (new DateTime())->setTimestamp($expires);
            }

            return [
                'title'     => (string) ($row['title'] ?? ''),
                'hash'      => (string) ($row['hash'] ?? ''),
                'status'    => $this->stateToStatus(state: $state, expires: $expires),
                'expiresAt' => $expiresAt,
            ];
        } catch (Throwable $e) {
            $this->logger->debug('Form metadata snapshot failed: '.$e->getMessage());
            return $defaults;
        }//end try

    }//end snapshotFormMetadata()

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
     * @param integer $state   NC Forms `state` column.
     * @param integer $expires NC Forms `expires` epoch (0 = no expiry).
     *
     * @return string Canonical status.
     */
    private function stateToStatus(int $state, int $expires): string
    {
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
    private function countSubmissions(int $formId): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('forms_v2_submissions')
                ->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
            $result = $qb->executeQuery();
            $count  = (int) $result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (Throwable $e) {
            return 0;
        }

    }//end countSubmissions()
}//end class
