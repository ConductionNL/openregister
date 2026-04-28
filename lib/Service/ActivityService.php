<?php

/**
 * OpenRegister ActivityService.
 *
 * Service for publishing OpenRegister activity events to the Nextcloud activity stream.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\Activity\IManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing OpenRegister activity events.
 */
class ActivityService
{
    /**
     * Constructor.
     *
     * @param IManager        $activityManager The activity manager.
     * @param IUserSession    $userSession     The user session.
     * @param IURLGenerator   $urlGenerator    The URL generator.
     * @param LoggerInterface $logger          The logger.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function __construct(
        private IManager $activityManager,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Publish an activity event for a created object.
     *
     * @param ObjectEntity $object The created object entity.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishObjectCreated(ObjectEntity $object): void
    {
        $title = $this->resolveTitle(primary: $object->getName(), fallback: $object->getUuid());
        $link  = $this->buildObjectLink(object: $object);

        $this->publish(
            subject: 'object_created',
            type: 'openregister_objects',
            parameters: ['title' => $title],
            objectType: 'object',
            objectId: (string) $object->getId(),
            objectName: $title,
            link: $link,
            ownerUserId: $object->getOwner()
        );
    }//end publishObjectCreated()

    /**
     * Publish an activity event for an updated object.
     *
     * @param ObjectEntity  $newObject The updated object entity.
     * @param ?ObjectEntity $oldObject The previous object entity state.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $oldObject reserved for future diff support
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishObjectUpdated(ObjectEntity $newObject, ?ObjectEntity $oldObject=null): void
    {
        $title = $this->resolveTitle(primary: $newObject->getName(), fallback: $newObject->getUuid());
        $link  = $this->buildObjectLink(object: $newObject);

        $this->publish(
            subject: 'object_updated',
            type: 'openregister_objects',
            parameters: ['title' => $title],
            objectType: 'object',
            objectId: (string) $newObject->getId(),
            objectName: $title,
            link: $link,
            ownerUserId: $newObject->getOwner()
        );
    }//end publishObjectUpdated()

    /**
     * Publish an activity event for a deleted object.
     *
     * @param ObjectEntity $object The deleted object entity.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishObjectDeleted(ObjectEntity $object): void
    {
        $title = $this->resolveTitle(primary: $object->getName(), fallback: $object->getUuid());

        $this->publish(
            subject: 'object_deleted',
            type: 'openregister_objects',
            parameters: ['title' => $title],
            objectType: 'object',
            objectId: (string) $object->getId(),
            objectName: $title,
            link: '',
            ownerUserId: $object->getOwner()
        );
    }//end publishObjectDeleted()

    /**
     * Publish an activity event for a created register.
     *
     * @param Register $register The created register.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishRegisterCreated(Register $register): void
    {
        $title = $this->resolveTitle(primary: $register->getTitle(), fallback: $register->getUuid());
        $link  = $this->buildRegisterLink(register: $register);

        $this->publish(
            subject: 'register_created',
            type: 'openregister_registers',
            parameters: ['title' => $title],
            objectType: 'register',
            objectId: (string) $register->getId(),
            objectName: $title,
            link: $link,
            ownerUserId: $register->getOwner()
        );
    }//end publishRegisterCreated()

    /**
     * Publish an activity event for an updated register.
     *
     * @param Register $register The updated register.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishRegisterUpdated(Register $register): void
    {
        $title = $this->resolveTitle(primary: $register->getTitle(), fallback: $register->getUuid());
        $link  = $this->buildRegisterLink(register: $register);

        $this->publish(
            subject: 'register_updated',
            type: 'openregister_registers',
            parameters: ['title' => $title],
            objectType: 'register',
            objectId: (string) $register->getId(),
            objectName: $title,
            link: $link,
            ownerUserId: $register->getOwner()
        );
    }//end publishRegisterUpdated()

    /**
     * Publish an activity event for a deleted register.
     *
     * @param Register $register The deleted register.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishRegisterDeleted(Register $register): void
    {
        $title = $this->resolveTitle(primary: $register->getTitle(), fallback: $register->getUuid());

        $this->publish(
            subject: 'register_deleted',
            type: 'openregister_registers',
            parameters: ['title' => $title],
            objectType: 'register',
            objectId: (string) $register->getId(),
            objectName: $title,
            link: '',
            ownerUserId: $register->getOwner()
        );
    }//end publishRegisterDeleted()

    /**
     * Publish an activity event for a created schema.
     *
     * @param Schema $schema The created schema.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishSchemaCreated(Schema $schema): void
    {
        $title = $this->resolveTitle(primary: $schema->getTitle(), fallback: $schema->getUuid());
        $link  = $this->buildSchemaLink(schema: $schema);

        $this->publish(
            subject: 'schema_created',
            type: 'openregister_schemas',
            parameters: ['title' => $title],
            objectType: 'schema',
            objectId: (string) $schema->getId(),
            objectName: $title,
            link: $link,
            ownerUserId: $schema->getOwner()
        );
    }//end publishSchemaCreated()

    /**
     * Publish an activity event for an updated schema.
     *
     * @param Schema $schema The updated schema.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishSchemaUpdated(Schema $schema): void
    {
        $title = $this->resolveTitle(primary: $schema->getTitle(), fallback: $schema->getUuid());
        $link  = $this->buildSchemaLink(schema: $schema);

        $this->publish(
            subject: 'schema_updated',
            type: 'openregister_schemas',
            parameters: ['title' => $title],
            objectType: 'schema',
            objectId: (string) $schema->getId(),
            objectName: $title,
            link: $link,
            ownerUserId: $schema->getOwner()
        );
    }//end publishSchemaUpdated()

    /**
     * Publish an activity event for a deleted schema.
     *
     * @param Schema $schema The deleted schema.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    public function publishSchemaDeleted(Schema $schema): void
    {
        $title = $this->resolveTitle(primary: $schema->getTitle(), fallback: $schema->getUuid());

        $this->publish(
            subject: 'schema_deleted',
            type: 'openregister_schemas',
            parameters: ['title' => $title],
            objectType: 'schema',
            objectId: (string) $schema->getId(),
            objectName: $title,
            link: '',
            ownerUserId: $schema->getOwner()
        );
    }//end publishSchemaDeleted()

    /**
     * Build a deep link to an object in the OpenRegister UI.
     *
     * @param ObjectEntity $object The object entity.
     *
     * @return string The absolute URL to the object.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    private function buildObjectLink(ObjectEntity $object): string
    {
        $baseUrl    = $this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page');
        $registerId = $object->getRegister();
        $schemaId   = $object->getSchema();
        $uuid       = $object->getUuid();

        return $baseUrl.'#/registers/'.$registerId.'/schemas/'.$schemaId.'/objects/'.$uuid;
    }//end buildObjectLink()

    /**
     * Build a deep link to a register in the OpenRegister UI.
     *
     * @param Register $register The register entity.
     *
     * @return string The absolute URL to the register.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    private function buildRegisterLink(Register $register): string
    {
        $baseUrl = $this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page');

        return $baseUrl.'#/registers/'.$register->getId();
    }//end buildRegisterLink()

    /**
     * Build a deep link to a schema in the OpenRegister UI.
     *
     * @param Schema $schema The schema entity.
     *
     * @return string The absolute URL to the schema.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    private function buildSchemaLink(Schema $schema): string
    {
        $baseUrl = $this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page');

        return $baseUrl.'#/schemas/'.$schema->getId();
    }//end buildSchemaLink()

    /**
     * Publish an activity event.
     *
     * Handles author resolution, affected user logic (including dual-notification
     * for object owners), and error handling.
     *
     * @param string  $subject     The activity subject.
     * @param string  $type        The activity type.
     * @param array   $parameters  The activity parameters.
     * @param string  $objectType  The object type.
     * @param string  $objectId    The object ID.
     * @param string  $objectName  The object name.
     * @param string  $link        The link to the entity.
     * @param ?string $ownerUserId The entity owner user ID for dual-notification.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    private function publish(
        string $subject,
        string $type,
        array $parameters,
        string $objectType,
        string $objectId,
        string $objectName,
        string $link,
        ?string $ownerUserId=null,
    ): void {
        try {
            $currentUser = $this->userSession->getUser();
            $author      = '';
            if ($currentUser !== null) {
                $author = $currentUser->getUID();
            }

            // Determine affected user: the author, or the owner in system context.
            $affectedUser = $author;
            if ($affectedUser === '' && $ownerUserId !== null && $ownerUserId !== '') {
                $affectedUser = $ownerUserId;
            }

            // If no affected user can be determined, skip publishing.
            if ($affectedUser === '') {
                return;
            }

            // Publish event for the acting user.
            $this->publishEvent(
                subject: $subject,
                type: $type,
                parameters: $parameters,
                objectType: $objectType,
                objectId: $objectId,
                objectName: $objectName,
                link: $link,
                author: $author,
                affectedUser: $affectedUser
            );

            // Dual-notification: if the owner differs from the author, notify the owner too.
            if ($ownerUserId !== null
                && $ownerUserId !== ''
                && $ownerUserId !== $author
                && $author !== ''
            ) {
                $this->publishEvent(
                    subject: $subject,
                    type: $type,
                    parameters: $parameters,
                    objectType: $objectType,
                    objectId: $objectId,
                    objectName: $objectName,
                    link: $link,
                    author: $author,
                    affectedUser: $ownerUserId
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to publish OpenRegister activity',
                [
                    'subject'   => $subject,
                    'type'      => $type,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end publish()

    /**
     * Publish a single activity event to the Nextcloud activity manager.
     *
     * @param string $subject      The activity subject.
     * @param string $type         The activity type.
     * @param array  $parameters   The activity parameters.
     * @param string $objectType   The object type.
     * @param string $objectId     The object ID.
     * @param string $objectName   The object name.
     * @param string $link         The link to the entity.
     * @param string $author       The author user ID.
     * @param string $affectedUser The affected user ID.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    private function publishEvent(
        string $subject,
        string $type,
        array $parameters,
        string $objectType,
        string $objectId,
        string $objectName,
        string $link,
        string $author,
        string $affectedUser,
    ): void {
        $event = $this->activityManager->generateEvent();
        $event->setApp(Application::APP_ID)
            ->setType($type)
            ->setAuthor($author)
            ->setTimestamp(time())
            ->setSubject($subject, $parameters)
            ->setObject($objectType, (int) $objectId, $objectName)
            ->setAffectedUser($affectedUser);

        if ($link !== '') {
            $event->setLink($link);
        }

        $this->activityManager->publish($event);
    }//end publishEvent()

    /**
     * Resolve a display title from primary and fallback values.
     *
     * @param string|null $primary  The primary title candidate.
     * @param string|null $fallback The fallback title candidate.
     *
     * @return string The resolved title.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-1
     */
    private function resolveTitle(?string $primary, ?string $fallback): string
    {
        if ($primary !== null && $primary !== '') {
            return $primary;
        }

        if ($fallback !== null && $fallback !== '') {
            return $fallback;
        }

        return 'Unknown';

    }//end resolveTitle()
}//end class
