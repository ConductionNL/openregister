<?php

/**
 * NoteService
 *
 * Service that wraps Nextcloud's ICommentsManager for adding notes to OpenRegister objects.
 * Notes are stored as standard Nextcloud comments with objectType "openregister".
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * NoteService wraps ICommentsManager for OpenRegister object notes.
 *
 * Provides methods to create, list, and delete notes (comments)
 * linked to OpenRegister objects using the objectType "openregister".
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class NoteService
{

    /**
     * Comments manager for note operations.
     *
     * @var ICommentsManager
     */
    private readonly ICommentsManager $commentsManager;

    /**
     * User session for getting current user.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * User manager for resolving display names.
     *
     * @var IUserManager
     */
    private readonly IUserManager $userManager;

    /**
     * Logger for error reporting.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * The objectType used for OpenRegister comments.
     *
     * @var string
     */
    private const OBJECT_TYPE = 'openregister';

    /**
     * Constructor.
     *
     * @param ICommentsManager $commentsManager Comments manager for CRUD operations
     * @param IUserSession     $userSession     User session for current user context
     * @param IUserManager     $userManager     User manager for display name resolution
     * @param LoggerInterface  $logger          Logger for error reporting
     *
     * @return void
     */
    public function __construct(
        ICommentsManager $commentsManager,
        IUserSession $userSession,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        $this->commentsManager = $commentsManager;
        $this->userSession     = $userSession;
        $this->userManager     = $userManager;
        $this->logger          = $logger;
    }//end __construct()

    /**
     * Get notes for a specific OpenRegister object.
     *
     * @param string $objectUuid The UUID of the OpenRegister object
     * @param int    $limit      Maximum number of notes to return (default 50)
     * @param int    $offset     Number of notes to skip (default 0)
     *
     * @return array Array of note arrays in JSON-friendly format
     */
    public function getNotesForObject(string $objectUuid, int $limit = 50, int $offset = 0): array
    {
        $comments = $this->commentsManager->getForObject(
            self::OBJECT_TYPE,
            $objectUuid,
            $limit,
            $offset
        );

        $notes = [];
        foreach ($comments as $comment) {
            $notes[] = $this->commentToArray($comment);
        }

        return $notes;
    }//end getNotesForObject()

    /**
     * Create a new note on an OpenRegister object.
     *
     * @param string $objectUuid The UUID of the OpenRegister object
     * @param string $message    The note message content
     *
     * @return array The created note in JSON-friendly format
     *
     * @throws Exception If no user is logged in
     */
    public function createNote(string $objectUuid, string $message): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $comment = $this->commentsManager->create(
            'users',
            $user->getUID(),
            self::OBJECT_TYPE,
            $objectUuid
        );

        $comment->setMessage($message);
        $this->commentsManager->save($comment);

        return $this->commentToArray($comment);
    }//end createNote()

    /**
     * Delete a note by its ID.
     *
     * @param int $noteId The ID of the note to delete
     *
     * @return void
     *
     * @throws Exception If the note is not found
     */
    public function deleteNote(int $noteId): void
    {
        try {
            $comment = $this->commentsManager->get((string) $noteId);
            $this->commentsManager->delete((string) $noteId);
        } catch (CommentsNotFoundException $e) {
            throw new Exception('Note not found');
        }
    }//end deleteNote()

    /**
     * Delete all notes for an OpenRegister object.
     *
     * Used for cleanup when an object is deleted.
     *
     * @param string $objectUuid The UUID of the OpenRegister object
     *
     * @return void
     */
    public function deleteNotesForObject(string $objectUuid): void
    {
        $this->commentsManager->deleteCommentsAtObject(
            self::OBJECT_TYPE,
            $objectUuid
        );
    }//end deleteNotesForObject()

    /**
     * Map an IComment to a JSON-friendly array.
     *
     * @param IComment $comment The comment to convert
     *
     * @return array The note in JSON-friendly format
     */
    private function commentToArray(IComment $comment): array
    {
        $actorId          = $comment->getActorId();
        $actorDisplayName = $actorId;

        // Resolve the display name from the user manager.
        $actorUser = $this->userManager->get($actorId);
        if ($actorUser !== null) {
            $actorDisplayName = $actorUser->getDisplayName();
        }

        // Check if the current user is the author.
        $isCurrentUser = false;
        $currentUser   = $this->userSession->getUser();
        if ($currentUser !== null) {
            $isCurrentUser = $currentUser->getUID() === $actorId;
        }

        return [
            'id'               => (int) $comment->getId(),
            'message'          => $comment->getMessage(),
            'actorType'        => $comment->getActorType(),
            'actorId'          => $actorId,
            'actorDisplayName' => $actorDisplayName,
            'createdAt'        => $comment->getCreationDateTime()->format('c'),
            'isCurrentUser'    => $isCurrentUser,
        ];
    }//end commentToArray()
}//end class
