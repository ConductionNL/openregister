<?php

/**
 * OpenRegister SurvivorshipController
 *
 * HTTP entry point for the per-object survivorship attribute-override
 * primitive (ADR-045 follow-on #E). Sets or clears one attribute override on
 * a master object's `overridesField` (default `attributeOverrides`), then
 * saves the object through {@see ObjectService::saveObject()} — the save
 * triggers {@see \OCA\OpenRegister\Listener\SurvivorshipRecomputeListener},
 * which recomputes the golden record with the override map threaded into
 * {@see \OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver}.
 * Authorisation is enforced entirely by the object read/write path
 * (RBAC/tenant scoped through `ObjectService`, same posture as
 * {@see MergeController}): a caller who cannot write the target object gets
 * a forbidden/not-found response rather than a successful override.
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
 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;
use Throwable;

class SurvivorshipController extends Controller
{
    /**
     * Default field the per-object attribute-override map is read/written to
     * when the schema's survivorship annotation omits `overridesField`.
     * Mirrors {@see \OCA\OpenRegister\Listener\SurvivorshipRecomputeListener}.
     *
     * @var string
     */
    private const DEFAULT_OVERRIDES_FIELD = 'attributeOverrides';

    /**
     * Constructor.
     *
     * @param string               $appName              The application name.
     * @param IRequest             $request              The current request.
     * @param ObjectService        $objectService        Object read/write path (RBAC + tenant scoped).
     * @param SchemaMapper         $schemaMapper         Schema lookup for the survivorship annotation.
     * @param SourceRecordResolver $sourceRecordResolver Mode-aware source-record resolver (embedded | reverseFk).
     * @param IUserSession         $userSession          Current user session, for actor attribution.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly SchemaMapper $schemaMapper,
        private readonly SourceRecordResolver $sourceRecordResolver,
        private readonly IUserSession $userSession
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Return a master object's resolved competing source records, honouring
     * the schema's `sourceLink` mode (embedded or reverse-FK). Used by the
     * conflict-resolution UI, which computes per-attribute disagreements from
     * these sources — a reverse-FK master carries no embedded source array, so
     * the client cannot resolve them without this endpoint.
     *
     * @param string $id Uuid of the master object.
     *
     * @return JSONResponse `{ sources: [...] }`, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    public function sources(string $id): JSONResponse
    {
        try {
            $object = $this->objectService->find(id: $id, _rbac: true, _multitenancy: true);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found.'], Http::STATUS_NOT_FOUND);
            }

            $sources = $this->sourceRecordResolver->resolveSources(
                masterData: ($object->getObject() ?? []),
                masterUuid: (string) $object->getUuid(),
                config: $this->survivorshipConfigFor(object: $object),
                masterRegister: (string) $object->getRegister()
            );
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(['sources' => $sources]);
    }//end sources()

    /**
     * Set (with a value) or clear (with a null/absent value) one attribute
     * override on a master object, then save it — the save fires
     * `SurvivorshipRecomputeListener`, which recomputes the golden record
     * with the override threaded in. Returns the recomputed object.
     *
     * A caller who cannot write the target object receives a
     * forbidden/not-found response; no override is written in that case
     * (no-admin-idor — the object id is never trusted without the
     * `ObjectService` write-path RBAC/tenant check).
     *
     * @param string $id Uuid of the master object.
     *
     * @return JSONResponse JSON response with the recomputed object.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.4
     */
    public function override(string $id): JSONResponse
    {
        $attribute = (string) $this->request->getParam('attribute', '');
        if ($attribute === '') {
            return new JSONResponse(['error' => 'An "attribute" is required.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            // RBAC/tenant scoped read — an unreadable object never reaches the
            // write path below.
            $object = $this->objectService->find(id: $id, _rbac: true, _multitenancy: true);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found.'], Http::STATUS_NOT_FOUND);
            }

            $this->applyOverride(object: $object, attribute: $attribute);

            // Warm the reverse-FK source resolution in this (clean) request
            // context before saving: resolving the source schema by slug here
            // populates the request-scoped schema cache, so the nested
            // recompute-on-save can resolve the sources by their numeric schema
            // id rather than a slug lookup that would run inside the save
            // transaction. Result discarded — this is a cache warm-up only.
            $this->sourceRecordResolver->resolveSources(
                masterData: ($object->getObject() ?? []),
                masterUuid: (string) $object->getUuid(),
                config: $this->survivorshipConfigFor(object: $object),
                masterRegister: (string) $object->getRegister()
            );

            // The RBAC/tenant-scoped write path: a caller who cannot write
            // this object throws NotAuthorizedException here, caught below.
            $saved = $this->objectService->saveObject(
                object: $object,
                register: $object->getRegister(),
                schema: $object->getSchema(),
                uuid: $object->getUuid()
            );
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }//end try

        $result       = ($saved->getObject() ?? []);
        $result['id'] = $saved->getUuid();

        return new JSONResponse($result);
    }//end override()

    /**
     * Set or clear one attribute override directly on the object's in-memory
     * payload (the caller is responsible for persisting it). Reads `value`,
     * `clear` and `rationale` from the current request.
     *
     * @param ObjectEntity $object    Object being overridden (mutated in place).
     * @param string       $attribute Attribute name to set/clear an override for.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.4
     */
    private function applyOverride(ObjectEntity $object, string $attribute): void
    {
        $hasValue = ($this->request->getParam('clear', false) !== true)
            && $this->request->getParam('value', null) !== null;

        $data           = ($object->getObject() ?? []);
        $overridesField = $this->overridesFieldFor(object: $object);

        $overrides = ($data[$overridesField] ?? []);
        if (is_array($overrides) === false) {
            $overrides = [];
        }

        unset($overrides[$attribute]);
        if ($hasValue === true) {
            $overrides[$attribute] = $this->buildOverrideEntry();
        }

        $data[$overridesField] = $overrides;
        $object->setObject($data);
    }//end applyOverride()

    /**
     * Build a single override entry from the current request's `value` and
     * `rationale` params, attributed to the acting user.
     *
     * @return array{value: mixed, overriddenBy: string, rationale: string|null}
     *
     * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.4
     */
    private function buildOverrideEntry(): array
    {
        $value     = $this->request->getParam('value', null);
        $rationale = $this->request->getParam('rationale', null);
        $actor     = ((string) ($this->userSession->getUser()?->getUID() ?? ''));

        $rationaleValue = null;
        if ($rationale !== null) {
            $rationaleValue = (string) $rationale;
        }

        return [
            'value'        => $value,
            'overriddenBy' => $actor,
            'rationale'    => $rationaleValue,
        ];
    }//end buildOverrideEntry()

    /**
     * Resolve the `overridesField` name declared on the object's schema
     * survivorship annotation, falling back to the listener's default.
     *
     * @param ObjectEntity $object Object whose schema to inspect.
     *
     * @return string The override-map field name.
     *
     * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.4
     */
    private function overridesFieldFor(ObjectEntity $object): string
    {
        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return self::DEFAULT_OVERRIDES_FIELD;
        }

        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-survivorship'] ?? null);
        if (is_array($value) === false) {
            return self::DEFAULT_OVERRIDES_FIELD;
        }

        $field = (string) ($value['overridesField'] ?? '');
        if ($field === '') {
            return self::DEFAULT_OVERRIDES_FIELD;
        }

        return $field;
    }//end overridesFieldFor()

    /**
     * Resolve the full `x-openregister-survivorship` config for an object's
     * schema (carrying the `sourceLink` block), or an empty array when absent.
     *
     * @param ObjectEntity $object Object whose schema to inspect.
     *
     * @return array<string, mixed> Survivorship config.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    private function survivorshipConfigFor(ObjectEntity $object): array
    {
        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return [];
        }

        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-survivorship'] ?? null);
        if (is_array($value) === false) {
            return [];
        }

        return $value;
    }//end survivorshipConfigFor()

    /**
     * Look up the schema referenced by an object instance.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     *
     * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.4
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $ref = $object->getSchema();
        if ($ref === null || $ref === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($ref, _multitenancy: false);
        } catch (Throwable) {
            return null;
        }
    }//end loadSchema()
}//end class
