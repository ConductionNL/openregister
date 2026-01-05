<?php

/**
 * TaggingHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Handles file tagging operations with Single Responsibility.
 *
 * This handler is responsible ONLY for:
 * - Attaching tags to files
 * - Retrieving tags from files
 * - Generating object tags
 * - Managing system tags
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class TaggingHandler
{
    /**
     * File tag type identifier.
     *
     * @var string
     */
    private const FILE_TAG_TYPE = 'files';

    /**
     * Constructor for TaggingHandler.
     *
     * @param ISystemTagManager      $systemTagManager System tag manager.
     * @param ISystemTagObjectMapper $systemTagMapper  System tag object mapper.
     * @param LoggerInterface        $logger           Logger for logging operations.
     */
    public function __construct(
        private readonly ISystemTagManager $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Attach tags to a file.
     *
     * @param string $fileId The file ID.
     * @param array  $tags   Tags to associate with the file.
     *
     * @return void
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param   array<int, string> $tags
     */
    public function attachTagsToFile(string $fileId, array $tags=[]): void
    {
        // Get all existing tags for the file and convert to array of just the IDs.
        $oldTagIdsResult = $this->systemTagMapper->getTagIdsForObjects(objIds: [$fileId], objectType: self::FILE_TAG_TYPE);
        $oldTagIds       = [];
        if (isset($oldTagIdsResult[$fileId]) === true && empty($oldTagIdsResult[$fileId]) === false) {
            $oldTagIds = $oldTagIdsResult[$fileId];
        }

        // Create new tags if they don't exist.
        $newTagIds = [];
        foreach ($tags as $tag) {
            // Check if tag exists by trying to get it by name.
            try {
                $tagObj = $this->systemTagManager->getTagsByIds([$tag]);
                if (empty($tagObj) === false) {
                    // Tag exists (found by ID), just use its ID.
                    $newTagIds[] = $tag;
                } else {
                    // Tag doesn't exist with this ID, search by name and create if needed.
                    $existingTag = $this->findOrCreateTag($tag);
                    $newTagIds[] = $existingTag->getId();
                }
            } catch (TagNotFoundException) {
                // Tag doesn't exist, create it.
                $newTag      = $this->findOrCreateTag($tag);
                $newTagIds[] = $newTag->getId();
            } catch (Exception $e) {
                $this->logger->error('Error processing tag '.$tag.': '.$e->getMessage());
                // Try to create it anyway.
                try {
                    $newTag      = $this->findOrCreateTag($tag);
                    $newTagIds[] = $newTag->getId();
                } catch (Exception $e2) {
                    $this->logger->error('Failed to create tag '.$tag.': '.$e2->getMessage());
                }
            }//end try
        }//end foreach

        // Calculate tags to add and remove.
        $tagsToAdd    = array_diff($newTagIds, $oldTagIds);
        $tagsToRemove = array_diff($oldTagIds, $newTagIds);

        // Remove old tags.
        if (empty($tagsToRemove) === false) {
            $this->systemTagMapper->unassignTags(objId: $fileId, objectType: self::FILE_TAG_TYPE, tagIds: $tagsToRemove);
        }

        // Add new tags.
        if (empty($tagsToAdd) === false) {
            $this->systemTagMapper->assignTags(objId: $fileId, objectType: self::FILE_TAG_TYPE, tagIds: $tagsToAdd);
        }
    }//end attachTagsToFile()

    /**
     * Find tag by name or create it if it doesn't exist.
     *
     * @param string $tagName Tag name.
     *
     * @return ISystemTag The tag object.
     *
     * @throws Exception If tag creation fails.
     */
    private function findOrCreateTag(string $tagName): ISystemTag
    {
        try {
            // Search for tag by name.
            $allTags = $this->systemTagManager->getAllTags(visibilityFilter: null, nameSearchPattern: $tagName);
            foreach ($allTags as $tag) {
                if ($tag->getName() === $tagName) {
                    return $tag;
                }
            }

            // Tag not found, create it.
            return $this->systemTagManager->createTag(
                tagName: $tagName,
                userVisible: true,
                userAssignable: true
            );
        } catch (TagAlreadyExistsException $e) {
            // Tag exists, get it by searching again.
            $allTags = $this->systemTagManager->getAllTags(visibilityFilter: null, nameSearchPattern: $tagName);
            foreach ($allTags as $tag) {
                if ($tag->getName() === $tagName) {
                    return $tag;
                }
            }

            throw new Exception('Tag exists but could not be found: '.$tagName);
        }//end try
    }//end findOrCreateTag()

    /**
     * Get tags for a file.
     *
     * @param string $fileId The file ID.
     *
     * @return string[]
     *
     * @phpstan-return array<int, string>
     *
     * @psalm-return list<string>
     */
    public function getFileTags(string $fileId): array
    {
        try {
            // Get tag IDs for the file.
            $tagIds = $this->systemTagMapper->getTagIdsForObjects(objIds: [$fileId], objectType: self::FILE_TAG_TYPE);

            if (isset($tagIds[$fileId]) === false || empty($tagIds[$fileId]) === true) {
                return [];
            }

            // Get actual tag objects from IDs.
            $tags = $this->systemTagManager->getTagsByIds($tagIds[$fileId]);

            // Extract tag names.
            $tagNames = [];
            foreach ($tags as $tag) {
                $tagNames[] = $tag->getName();
            }

            return $tagNames;
        } catch (Exception $e) {
            $this->logger->error('Error getting tags for file '.$fileId.': '.$e->getMessage());
            return [];
        }//end try
    }//end getFileTags()

    /**
     * Generate an object tag from an object entity.
     *
     * @param ObjectEntity|string $objectEntity Object entity or UUID.
     *
     * @return string The object tag (e.g., 'object:uuid').
     */
    public function generateObjectTag(ObjectEntity|string $objectEntity): string
    {
        $identifier = $objectEntity;
        if ($objectEntity instanceof ObjectEntity === true) {
            $identifier = $objectEntity->getUuid() ?? (string) $objectEntity->getId();
        }

        return 'object:'.$identifier;
    }//end generateObjectTag()

    /**
     * Get all system tags.
     *
     * @return string[]
     *
     * @phpstan-return array<int, string>
     *
     * @psalm-return list<string>
     */
    public function getAllTags(): array
    {
        try {
            $tags = $this->systemTagManager->getAllTags(visibilityFilter: null);

            $tagNames = [];
            foreach ($tags as $tag) {
                $tagNames[] = $tag->getName();
            }

            return $tagNames;
        } catch (Exception $e) {
            $this->logger->error('Error getting all tags: '.$e->getMessage());
            return [];
        }
    }//end getAllTags()
}//end class
