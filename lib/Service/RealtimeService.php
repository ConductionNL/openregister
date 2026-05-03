<?php

/**
 * OpenRegister RealtimeService
 *
 * Records register-object changes as CloudEvent-shaped rows in
 * `openregister_realtime_events`. Clients poll
 * `GET /api/realtime/events?since={cursor}` to receive every event
 * newer than their last seen id (cursor-based long-polling pattern).
 *
 * v1 scope:
 *   - object created / updated / deleted / transitioned events
 *   - cursor-based polling (clients can poll every 1-2s)
 *   - subscription filters: register / schema / objectUuid / eventType
 *   - organisation-scoped (multi-tenancy gate via the actor's session org)
 *   - retention pruning via daily TimedJob
 *
 * Out of scope for v1 (deferred to v1.1 in the spec):
 *   - SSE long-lived connections (PHP-FPM unfriendly)
 *   - notify_push integration (NC infra)
 *   - debounce / batch coalescing
 *   - frontend reactive store wiring
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RealtimeEvent;
use OCA\OpenRegister\Db\RealtimeEventMapper;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RealtimeService
{

    public const TYPE_OBJECT_CREATED      = 'or.object.created';
    public const TYPE_OBJECT_UPDATED      = 'or.object.updated';
    public const TYPE_OBJECT_DELETED      = 'or.object.deleted';
    public const TYPE_OBJECT_TRANSITIONED = 'or.object.transitioned';

    /**
     * Constructor.
     *
     * @param RealtimeEventMapper $eventMapper  The realtime event mapper.
     * @param IUserSession        $userSession  The user session.
     * @param IURLGenerator       $urlGenerator The URL generator.
     * @param UrnService          $urnService   The URN service.
     * @param LoggerInterface     $logger       The logger.
     */
    public function __construct(
        private readonly RealtimeEventMapper $eventMapper,
        private readonly IUserSession $userSession,
        private readonly IURLGenerator $urlGenerator,
        private readonly UrnService $urnService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Record a CloudEvent-shaped change record for a register object.
     *
     * Returns null when the underlying DB write fails — caller is the
     * event listener which logs + continues; one missed realtime event
     * MUST NOT break the actual save pipeline.
     *
     * @param string               $eventType The event type (e.g. or.object.created).
     * @param ObjectEntity         $object    The register object.
     * @param array<string, mixed> $extra     Trigger-specific extras (e.g. transition action/from/to).
     *
     * @return RealtimeEvent|null The persisted event entity, or null on failure.
     */
    public function record(string $eventType, ObjectEntity $object, array $extra=[]): ?RealtimeEvent
    {
        try {
            $actor = $this->userSession->getUser()?->getUID();
            $urn   = $this->urnService->buildForObject($object);
            $base  = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');

            // CloudEvents 1.0 envelope.
            $payload = [
                'specversion'     => '1.0',
                'type'            => $eventType,
                'source'          => $base.'/apps/openregister',
                'subject'         => $urn ?? (string) $object->getUuid(),
                'id'              => bin2hex(random_bytes(16)),
                'time'            => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'datacontenttype' => 'application/json',
                'data'            => [
                    'register'     => $object->getRegister(),
                    'schema'       => $object->getSchema(),
                    'uuid'         => $object->getUuid(),
                    'urn'          => $urn,
                    'organisation' => $object->getOrganisation(),
                    'owner'        => $object->getOwner(),
                    'actor'        => $actor,
                    'extra'        => $extra,
                ],
            ];

            $event = new RealtimeEvent();
            $event->setEventType($eventType);
            $event->setSource($base.'/apps/openregister');
            $event->setSubject($urn ?? (string) $object->getUuid());
            $event->setRegisterId((string) $object->getRegister());
            $event->setSchemaId((string) $object->getSchema());
            $event->setObjectUuid((string) $object->getUuid());
            $event->setActorUid($actor);
            $event->setOrganisation((string) $object->getOrganisation());
            $event->setPayload(json_encode($payload, JSON_UNESCAPED_SLASHES));
            $event->setCreated(new DateTime());

            return $this->eventMapper->insert($event);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[RealtimeService] failed to record %s: %s', $eventType, $e->getMessage())
            );
            return null;
        }//end try
    }//end record()
}//end class
