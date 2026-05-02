<?php

/**
 * VngNotificatiesEnvelope — VNG Notificaties API envelope mapper.
 *
 * Pure mapping that turns an OR notification event into a payload
 * conforming to the VNG Notificaties API standard:
 *
 *   {
 *     "kanaal":       "<register slug>",
 *     "hoofdObject":  "<baseUrl>/api/v1/<register>/<object-uuid>",
 *     "resource":     "<schema slug>",
 *     "resourceUrl":  "<baseUrl>/api/v1/<schema>/<object-uuid>",
 *     "actie":        "create | update | partial_update | destroy",
 *     "aanmaakdatum": "<ISO 8601 UTC>",
 *     "kenmerken":    { "...": "..." }
 *   }
 *
 * The spec is explicit that VNG envelope production goes through the
 * generic Webhook + Mapping pipeline (Twig template lives in a Mapping
 * entity); this class is the canonical pure-PHP implementation of that
 * mapping so:
 *   1. consumers can call it directly when bypassing the Mapping pipeline,
 *   2. the algorithmic correctness has unit-test coverage independent of
 *      the Twig stack.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/notificatie-engine/specs/notificatie-engine/spec.md "VNG Notificaties API compliance"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Maps OR notification events to the VNG Notificaties envelope.
 */
class VngNotificatiesEnvelope
{

    /**
     * The OR-internal action name MUST map to one of these VNG actie values.
     *
     * @var array<string, string>
     */
    private const ACTION_MAP = [
        'create'         => 'create',
        'created'        => 'create',
        'update'         => 'update',
        'updated'        => 'update',
        'partial_update' => 'partial_update',
        'patched'        => 'partial_update',
        'destroy'        => 'destroy',
        'delete'         => 'destroy',
        'deleted'        => 'destroy',
    ];

    /**
     * Build a VNG envelope from the input parts.
     *
     * @param string             $action       OR-internal action; mapped to a VNG actie.
     * @param string             $registerSlug Register slug (becomes `kanaal`).
     * @param string             $schemaSlug   Schema slug (becomes `resource`).
     * @param string             $objectUuid   Parent object UUID.
     * @param string             $baseUrl      External base URL (no trailing slash).
     * @param ?DateTimeInterface $timestamp    Event timestamp; null = now.
     * @param array              $kenmerken    Extra discriminators (zaaktype, status, etc.).
     *
     * @return array{kanaal: string, hoofdObject: string, resource: string, resourceUrl: string, actie: string, aanmaakdatum: string, kenmerken: array}
     *
     * @throws InvalidArgumentException When the action is not a recognised VNG actie.
     */
    public function buildEnvelope(
        string $action,
        string $registerSlug,
        string $schemaSlug,
        string $objectUuid,
        string $baseUrl,
        ?DateTimeInterface $timestamp=null,
        array $kenmerken=[]
    ): array {
        $vngActie = $this->mapAction(action: $action);
        $base     = rtrim($baseUrl, '/');
        $when     = ($timestamp ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return [
            'kanaal'       => $registerSlug,
            'hoofdObject'  => $base.'/api/v1/'.$registerSlug.'/'.$objectUuid,
            'resource'     => $schemaSlug,
            'resourceUrl'  => $base.'/api/v1/'.$schemaSlug.'/'.$objectUuid,
            'actie'        => $vngActie,
            'aanmaakdatum' => $when->format(DateTimeInterface::ATOM),
            'kenmerken'    => $kenmerken,
        ];

    }//end buildEnvelope()

    /**
     * Map an OR-internal action verb to the VNG actie string.
     *
     * Per the VNG Notificaties API, the only legal `actie` values are
     * `create`, `update`, `partial_update`, `destroy`. We accept the
     * common OR-internal synonyms (`created`, `updated`, etc.) and map
     * them; anything else throws so a misconfigured webhook can't
     * silently emit an out-of-spec envelope.
     *
     * @param string $action The OR-internal action.
     *
     * @return string The VNG actie value.
     *
     * @throws InvalidArgumentException When the action is unrecognised.
     */
    public function mapAction(string $action): string
    {
        $key = strtolower($action);
        if (isset(self::ACTION_MAP[$key]) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'VNG Notificaties: unsupported action "%s". Allowed: %s',
                    $action,
                    implode(', ', array_unique(array_values(self::ACTION_MAP)))
                )
            );
        }

        return self::ACTION_MAP[$key];

    }//end mapAction()
}//end class
