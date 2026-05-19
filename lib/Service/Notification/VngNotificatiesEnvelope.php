<?php

/**
 * VngNotificatiesEnvelope
 *
 * Maps an OpenRegister notification event to the canonical VNG Notificaties
 * envelope: kanaal / hoofdObject / resource / resourceUrl / actie /
 * aanmaakdatum / kenmerken.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Produces VNG-compliant notification envelopes.
 *
 * @psalm-suppress UnusedClass
 */
class VngNotificatiesEnvelope
{

    /**
     * Map OR internal action names to VNG actie values.
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
     * Build a VNG Notificaties envelope array.
     *
     * @param string                 $action     OR action name (case-insensitive).
     * @param string                 $register   Register slug.
     * @param string                 $schema     Schema slug.
     * @param string                 $objectUuid Object UUID.
     * @param string                 $baseUrl    Base URL (trailing slash stripped).
     * @param DateTimeInterface|null $timestamp  Event timestamp; defaults to now().
     * @param array<string,string>   $kenmerken  Optional VNG kenmerken map.
     *
     * @return array<string,mixed> VNG envelope.
     *
     * @throws InvalidArgumentException When $action is not recognised.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-8
     */
    public function build(
        string $action,
        string $register,
        string $schema,
        string $objectUuid,
        string $baseUrl,
        ?DateTimeInterface $timestamp=null,
        array $kenmerken=[]
    ): array {
        $vngAction = $this->mapAction(action: $action);
        $base      = rtrim(string: $baseUrl, characters: '/');

        $hoofdObject = $base.'/api/registers/'.$register.'/'.$schema.'/'.$objectUuid;
        $resourceUrl = $hoofdObject;

        $ts = ($timestamp ?? new DateTimeImmutable())->format(format: DateTimeInterface::ATOM);

        return [
            'kanaal'       => $register.'.'.$schema,
            'hoofdObject'  => $hoofdObject,
            'resource'     => $schema,
            'resourceUrl'  => $resourceUrl,
            'actie'        => $vngAction,
            'aanmaakdatum' => $ts,
            'kenmerken'    => $kenmerken,
        ];
    }//end build()

    /**
     * Map an OR action alias to the canonical VNG actie value.
     *
     * @param string $action Raw action string (case-insensitive).
     *
     * @return string VNG actie value.
     *
     * @throws InvalidArgumentException When action is not in the map.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-8
     */
    public function mapAction(string $action): string
    {
        $lower = strtolower(string: $action);
        if (isset(self::ACTION_MAP[$lower]) === false) {
            throw new InvalidArgumentException("Unknown OR action: '$action'");
        }

        return self::ACTION_MAP[$lower];
    }//end mapAction()
}//end class
