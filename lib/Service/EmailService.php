<?php

/**
 * Service for managing email-to-object links.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Class EmailService
 *
 * Provides reverse-lookup and quick-link functionality for email-object links.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EmailService
{
    /**
     * Constructor.
     *
     * @param EmailLinkMapper $emailLinkMapper The email link mapper.
     * @param RegisterMapper  $registerMapper  The register mapper.
     * @param SchemaMapper    $schemaMapper    The schema mapper.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private readonly EmailLinkMapper $emailLinkMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Find objects linked to a specific email message.
     *
     * @param int $accountId The mail account ID.
     * @param int $messageId The mail message ID.
     *
     * @return array{results: array<int, array<string, mixed>>, total: int}
     */
    public function findByMessageId(int $accountId, int $messageId): array
    {
        $links   = $this->emailLinkMapper->findByAccountAndMessage($accountId, $messageId);
        $results = [];

        foreach ($links as $link) {
            $results[] = $this->resolveLink($link);
        }

        return [
            'results' => $results,
            'total'   => count($results),
        ];
    }//end findByMessageId()

    /**
     * Find objects linked to emails from a specific sender.
     *
     * Returns distinct objects with email count, ordered by count descending.
     *
     * @param string $sender The sender email address.
     *
     * @return array{results: array<int, array<string, mixed>>, total: int}
     */
    public function findObjectsBySender(string $sender): array
    {
        $rows    = $this->emailLinkMapper->findBySender($sender);
        $results = [];

        foreach ($rows as $row) {
            $registerTitle = $this->resolveRegisterTitle((int)$row['register_id']);
            $schemaTitle   = $this->resolveSchemaTitle(
                $row['schema_id'] !== null ? (int)$row['schema_id'] : null
            );

            $results[] = [
                'objectUuid'       => $row['object_uuid'],
                'registerId'       => (int)$row['register_id'],
                'registerTitle'    => $registerTitle,
                'schemaId'         => $row['schema_id'] !== null ? (int)$row['schema_id'] : null,
                'schemaTitle'      => $schemaTitle,
                'linkedEmailCount' => (int)$row['linked_email_count'],
            ];
        }

        return [
            'results' => $results,
            'total'   => count($results),
        ];
    }//end findObjectsBySender()

    /**
     * Create a quick link between an email and an object.
     *
     * @param array<string, mixed> $params The link parameters.
     *
     * @return array<string, mixed> The created link with resolved metadata.
     *
     * @throws \RuntimeException If a duplicate link exists or object not found.
     */
    public function quickLink(array $params): array
    {
        $accountId  = (int)$params['mailAccountId'];
        $messageId  = (int)$params['mailMessageId'];
        $objectUuid = (string)$params['objectUuid'];

        // Check for duplicate.
        $existing = $this->emailLinkMapper->findExistingLink(
            $accountId,
            $messageId,
            $objectUuid
        );
        if ($existing !== null) {
            throw new \RuntimeException('Email already linked to this object', 409);
        }

        $link = new EmailLink();
        $link->setMailAccountId($accountId);
        $link->setMailMessageId($messageId);
        $link->setMailMessageUid($params['mailMessageUid'] ?? null);
        $link->setSubject($params['subject'] ?? null);
        $link->setSender($params['sender'] ?? null);
        $link->setMailDate($params['date'] ?? null);
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId((int)$params['registerId']);
        $link->setSchemaId(
            isset($params['schemaId']) ? (int)$params['schemaId'] : null
        );
        $link->setLinkedBy($params['linkedBy'] ?? null);
        $link->setLinkedAt(new DateTime());

        $link = $this->emailLinkMapper->insert($link);

        return $this->resolveLink($link);
    }//end quickLink()

    /**
     * Delete an email link by ID.
     *
     * @param int $linkId The link ID.
     *
     * @return void
     *
     * @throws DoesNotExistException If the link does not exist.
     */
    public function deleteLink(int $linkId): void
    {
        $link = $this->emailLinkMapper->findById($linkId);
        $this->emailLinkMapper->delete($link);
    }//end deleteLink()

    /**
     * Resolve an EmailLink entity to an array with register/schema titles.
     *
     * @param EmailLink $link The email link entity.
     *
     * @return array<string, mixed> The resolved link data.
     */
    private function resolveLink(EmailLink $link): array
    {
        $registerTitle = $this->resolveRegisterTitle($link->getRegisterId());
        $schemaTitle   = $this->resolveSchemaTitle($link->getSchemaId());

        return [
            'linkId'        => $link->getId(),
            'objectUuid'    => $link->getObjectUuid(),
            'registerId'    => $link->getRegisterId(),
            'registerTitle' => $registerTitle,
            'schemaId'      => $link->getSchemaId(),
            'schemaTitle'   => $schemaTitle,
            'subject'       => $link->getSubject(),
            'sender'        => $link->getSender(),
            'linkedBy'      => $link->getLinkedBy(),
            'linkedAt'      => $link->getLinkedAt()?->format(DateTime::ATOM),
        ];
    }//end resolveLink()

    /**
     * Resolve a register title by ID.
     *
     * @param int|null $registerId The register ID.
     *
     * @return string|null The register title or null.
     */
    private function resolveRegisterTitle(?int $registerId): ?string
    {
        if ($registerId === null) {
            return null;
        }

        try {
            $register = $this->registerMapper->find($registerId);
            return $register->getTitle();
        } catch (\Exception $e) {
            $this->logger->warning(
                'Could not resolve register title for ID {id}',
                ['id' => $registerId, 'exception' => $e]
            );
            return null;
        }
    }//end resolveRegisterTitle()

    /**
     * Resolve a schema title by ID.
     *
     * @param int|null $schemaId The schema ID.
     *
     * @return string|null The schema title or null.
     */
    private function resolveSchemaTitle(?int $schemaId): ?string
    {
        if ($schemaId === null) {
            return null;
        }

        try {
            $schema = $this->schemaMapper->find($schemaId);
            return $schema->getTitle();
        } catch (\Exception $e) {
            $this->logger->warning(
                'Could not resolve schema title for ID {id}',
                ['id' => $schemaId, 'exception' => $e]
            );
            return null;
        }
    }//end resolveSchemaTitle()
}//end class
