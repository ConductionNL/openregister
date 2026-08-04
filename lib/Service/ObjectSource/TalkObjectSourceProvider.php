<?php

/**
 * TalkObjectSourceProvider — serves the `nc-conversation` virtual schema's objects
 * live from the acting user's Nextcloud Talk (spreed) conversations (read-only).
 *
 * The authoritative record is the Talk room held by the spreed app; this provider
 * projects each conversation the acting user is a participant of as a virtual
 * ObjectEntity (uuid = room token; object = {id, name, displayName, type,
 * participantCount, lastActivity}) and never writes back. Talk's own `Manager`
 * runs `getRoomsForUser()` scoped to the acting user's rooms, so the projection is
 * inherently user-scoped (denied == absent, no enumeration oracle) — mirroring the
 * scoping approach of the sibling object-source providers.
 *
 * Talk lives in ANOTHER app's namespace, so all access is gated behind
 * `class_exists` + a guarded container lookup (the defensive style of the CalDAV
 * and Deck object-source providers): when Talk is absent or its service classes
 * cannot be resolved, the projection degrades to an empty list with a logged
 * warning rather than erroring.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud Talk (spreed) app.
 */
class TalkObjectSourceProvider implements ObjectSourceProvider
{

    /**
     * NC app id whose install-state gates this provider.
     *
     * @var string
     */
    private const REQUIRED_APP = 'spreed';

    /**
     * Talk's room manager (resolved dynamically — Talk's namespace).
     *
     * @var string
     */
    private const MANAGER = 'OCA\\Talk\\Manager';

    /**
     * Talk's participant service (resolved dynamically — Talk's namespace).
     *
     * @var string
     */
    private const PARTICIPANT_SERVICE = 'OCA\\Talk\\Service\\ParticipantService';

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager  App availability checks.
     * @param IUserSession       $userSession The acting-user session.
     * @param ContainerInterface $container   Server container (lazy Talk-service lookup).
     * @param LoggerInterface    $logger      Logger for read failures.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function getId(): string
    {
        return 'talk-source';
    }//end getId()

    /**
     * {@inheritDoc}
     *
     * Gated on the Talk (spreed) app: when it is not installed the bound schema
     * degrades to an empty list rather than erroring.
     *
     * @return bool True when the Talk app is installed.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function isEnabled(): bool
    {
        try {
            return $this->appManager->isInstalled(self::REQUIRED_APP);
        } catch (Throwable $e) {
            return false;
        }
    }//end isEnabled()

    /**
     * {@inheritDoc}
     *
     * MUST return null when the conversation is absent OR the acting user is not
     * a participant, so the two cases are indistinguishable (no enumeration
     * oracle).
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param string               $id       The Talk room token.
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return ObjectEntity|null The virtual object, or null when absent/denied.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
    {
        foreach ($this->readRooms() as $room) {
            if ((string) ($room['id'] ?? '') === $id) {
                return $this->toObjectEntity(register: $register, schema: $schema, room: $room);
            }
        }

        return null;
    }//end find()

    /**
     * {@inheritDoc}
     *
     * Honours `limit` and `offset`. The result is scoped by Talk's `Manager` to
     * the acting user's own conversations.
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (limit/offset).
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return ObjectEntity[] The matching virtual objects.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
    {
        $limit  = (int) ($query['limit'] ?? 200);
        $offset = (int) ($query['offset'] ?? 0);

        $rooms = array_slice($this->readRooms(), $offset, $limit);

        $objects = [];
        foreach ($rooms as $room) {
            $objects[] = $this->toObjectEntity(register: $register, schema: $schema, room: $room);
        }

        return $objects;
    }//end findAll()

    /**
     * {@inheritDoc}
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (limit/offset).
     * @param array<string, mixed> $config   The object-source config block (unused).
     *
     * @return int The number of matching virtual objects.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
    {
        return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
    }//end count()

    /**
     * Read the Talk rooms the acting user participates in, failing closed to an
     * empty list.
     *
     * Every access is guarded so an absent Talk app or a per-room read failure
     * degrades to an empty/partial list rather than erroring.
     *
     * @return array<int, array<string, mixed>> The normalised room arrays.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function readRooms(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $manager = $this->resolveService(class: self::MANAGER);
        if ($manager === null) {
            return [];
        }

        try {
            $rooms = $manager->getRoomsForUser($user->getUID());
        } catch (Throwable $e) {
            $this->logger->warning('[ObjectSource:talk-source] could not list rooms: '.$e->getMessage());
            return [];
        }

        $participantService = $this->resolveService(class: self::PARTICIPANT_SERVICE);

        $result = [];
        foreach ($rooms as $room) {
            if (is_object($room) === false) {
                continue;
            }

            $result[] = $this->normaliseRoom(room: $room, userId: $user->getUID(), participantService: $participantService);
        }

        return $result;
    }//end readRooms()

    /**
     * Normalise a single Talk room entity into a flat array.
     *
     * @param object      $room               The Talk room entity.
     * @param string      $userId             The acting user's uid (for the display name).
     * @param object|null $participantService Talk's participant service (may be null).
     *
     * @return array<string, mixed> The normalised room array.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function normaliseRoom(object $room, string $userId, ?object $participantService): array
    {
        return [
            'id'               => $this->stringGetter(entity: $room, getter: 'getToken'),
            'name'             => $this->stringGetter(entity: $room, getter: 'getName'),
            'displayName'      => $this->displayName(room: $room, userId: $userId),
            'type'             => $this->intGetter(entity: $room, getter: 'getType'),
            'participantCount' => $this->participantCount(room: $room, participantService: $participantService),
            'lastActivity'     => $this->lastActivity(room: $room),
        ];
    }//end normaliseRoom()

    /**
     * Resolve one of Talk's service classes from the container, or null when
     * Talk is not installed / its service cannot be resolved.
     *
     * @param string $class The fully-qualified Talk service class name.
     *
     * @return object|null The resolved service, or null.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function resolveService(string $class): ?object
    {
        if (class_exists($class) === false) {
            return null;
        }

        try {
            $service = $this->container->get($class);
            if (is_object($service) === true) {
                return $service;
            }
        } catch (Throwable $e) {
            $this->logger->warning('[ObjectSource:talk-source] could not resolve '.$class.': '.$e->getMessage());
        }

        return null;
    }//end resolveService()

    /**
     * Resolve the acting-user display name for a room, defaulting to the room
     * name when the per-user getter is unavailable.
     *
     * @param object $room   The Talk room entity.
     * @param string $userId The acting user's uid.
     *
     * @return string The display name, or ''.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function displayName(object $room, string $userId): string
    {
        try {
            return (string) $room->getDisplayName($userId);
        } catch (Throwable $e) {
            return $this->stringGetter(entity: $room, getter: 'getName');
        }
    }//end displayName()

    /**
     * Resolve the participant count for a room, defaulting to 0 when the
     * participant service is unavailable.
     *
     * @param object      $room               The Talk room entity.
     * @param object|null $participantService Talk's participant service (may be null).
     *
     * @return int The participant count.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function participantCount(object $room, ?object $participantService): int
    {
        if ($participantService === null) {
            return 0;
        }

        try {
            return (int) $participantService->getNumberOfActors($room);
        } catch (Throwable $e) {
            return 0;
        }
    }//end participantCount()

    /**
     * Resolve a room's last-activity timestamp as an ISO-8601 string, or null.
     *
     * @param object $room The Talk room entity.
     *
     * @return string|null The ISO-8601 last activity, or null.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function lastActivity(object $room): ?string
    {
        try {
            $activity = $room->getLastActivity();
            if ($activity instanceof \DateTimeInterface) {
                return $activity->format(\DateTime::ATOM);
            }
        } catch (Throwable $e) {
            // Getter missing on this Talk version — leave null.
        }

        return null;
    }//end lastActivity()

    /**
     * Read an integer getter from a Talk entity, guarded.
     *
     * @param object $entity The Talk entity.
     * @param string $getter The getter method name.
     *
     * @return int|null The integer value, or null when unavailable.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function intGetter(object $entity, string $getter): ?int
    {
        try {
            $value = $entity->$getter();
            if ($value === null) {
                return null;
            }

            return (int) $value;
        } catch (Throwable $e) {
            return null;
        }
    }//end intGetter()

    /**
     * Read a string getter from a Talk entity, defaulting to '' on failure.
     *
     * @param object $entity The Talk entity.
     * @param string $getter The getter method name.
     *
     * @return string The string value, or ''.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function stringGetter(object $entity, string $getter): string
    {
        try {
            return (string) $entity->$getter();
        } catch (Throwable $e) {
            return '';
        }
    }//end stringGetter()

    /**
     * Map a normalised Talk room array onto a non-persisted virtual ObjectEntity.
     *
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $room     The normalised room array.
     *
     * @return ObjectEntity The virtual object (never saved).
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    private function toObjectEntity(Register $register, Schema $schema, array $room): ObjectEntity
    {
        $token = (string) ($room['id'] ?? '');

        $entity = new ObjectEntity();
        $entity->setUuid($token);
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($room);

        return $entity;
    }//end toObjectEntity()
}//end class
