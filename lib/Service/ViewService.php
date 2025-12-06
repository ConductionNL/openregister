<?php
/**
 * OpenRegister ViewService
 *
 * Service class for managing views in the OpenRegister application.
 *
 * This service acts as a facade for view operations,
 * coordinating between ViewMapper and business logic.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use Psr\Log\LoggerInterface;

/**
 * ViewService manages views in the OpenRegister application
 *
 * Service class for managing views in the OpenRegister application.
 * This service acts as a facade for view operations, coordinating between
 * ViewMapper and business logic. Handles view CRUD operations, access control,
 * and default view management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class ViewService
{

    /**
     * View mapper
     *
     * Handles database operations for view entities.
     *
     * @var ViewMapper View mapper instance
     */
    private readonly ViewMapper $viewMapper;

    /**
     * Logger
     *
     * Used for logging view operations and errors.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;


    /**
     * Constructor
     *
     * Initializes service with view mapper and logger for view operations.
     *
     * @param ViewMapper      $viewMapper View mapper for database operations
     * @param LoggerInterface $logger     Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        ViewMapper $viewMapper,
        LoggerInterface $logger
    ) {
        // Store dependencies for use in service methods.
        $this->viewMapper = $viewMapper;
        $this->logger     = $logger;

    }//end __construct()


    /**
     * Find a view by ID
     *
     * Retrieves view by ID and validates user access permissions.
     * Users can access their own views or public views.
     *
     * @param int|string $id    The ID of the view to find
     * @param string     $owner The owner user ID for access control
     *
     * @return View The found view entity
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found or access denied
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple views found (should not happen)
     * @throws \OCP\DB\Exception If database error occurs
     */
    public function find(int | string $id, string $owner): View
    {
        // Step 1: Find view by ID in database.
        $view = $this->viewMapper->find($id);

        // Step 2: Check if user has access to this view.
        // Users can access their own views or public views.
        if ($view->getOwner() !== $owner && $view->getIsPublic() === false) {
            // Throw exception to prevent unauthorized access.
            throw new \OCP\AppFramework\Db\DoesNotExistException('View not found or access denied');
        }

        // Step 3: Return view if access is granted.
        return $view;

    }//end find()


    /**
     * Find all views accessible to a user
     *
     * Retrieves all views that the user owns or has access to (public views).
     * Returns array of view entities sorted by default status and name.
     *
     * @param string $owner The owner user ID to find views for
     *
     * @return View[] Array of found views accessible to the user
     *
     * @psalm-return array<View>
     */
    public function findAll(string $owner): array
    {
        // Retrieve all views accessible to the user (owned or public).
        return $this->viewMapper->findAll(owner: $owner);

    }//end findAll()


    /**
     * Create a new view
     *
     * Creates a new view entity with specified properties. If view is set as default,
     * clears any existing default view for the user. Validates and stores view in database.
     *
     * @param string $name        The name of the view
     * @param string $description The description of the view
     * @param string $owner       The owner user ID
     * @param bool   $isPublic    Whether the view is public (accessible to all users)
     * @param bool   $isDefault   Whether the view is the default view for the user
     * @param array<string, mixed> $query The query parameters (registers, schemas, filters)
     *
     * @return View The created view entity
     *
     * @throws Exception If view creation fails (database error, validation error, etc.)
     */
    public function create(
        string $name,
        string $description,
        string $owner,
        bool $isPublic,
        bool $isDefault,
        array $query
    ): View {
        try {
            // Step 1: If this view is set as default, clear any existing default for this user.
            // Only one default view per user is allowed.
            if ($isDefault === true) {
                $this->clearDefaultForUser($owner);
            }

            // Step 2: Create new view entity and set all properties.
            $view = new View();
            $view->setName($name);
            $view->setDescription($description);
            $view->setOwner($owner);
            $view->setIsPublic($isPublic);
            $view->setIsDefault($isDefault);
            $view->setQuery($query);
            $view->setFavoredBy([]);

            // Step 3: Insert view into database and return created entity.
            return $this->viewMapper->insert($view);
        } catch (Exception $e) {
            // Log error for debugging and monitoring.
            $this->logger->error(message: 'Error creating view: '.$e->getMessage());
            throw $e;
        }

    }//end create()


    /**
     * Update an existing view.
     *
     * @param int|string $id          The ID of the view to update
     * @param string     $name        The name of the view
     * @param string     $description The description of the view
     * @param string     $owner       The owner user ID (for access control)
     * @param bool       $isPublic    Whether the view is public
     * @param bool       $isDefault   Whether the view is default
     * @param array      $query       The query parameters
     * @param array|null $favoredBy   Array of user IDs who favor this view
     *
     * @return View The updated view
     *
     * @throws Exception If update fails
     */
    public function update(
        int | string $id,
        string $name,
        string $description,
        string $owner,
        bool $isPublic,
        bool $isDefault,
        array $query,
        ?array $favoredBy=null
    ): View {
        try {
            $view = $this->find($id, $owner);

            // If this is set as default, schema: unset any existing default for this user.
            if ($isDefault === true && $view->getIsDefault() === false) {
                $this->clearDefaultForUser($owner);
            }

            $view->setName($name);
            $view->setDescription($description);
            $view->setIsPublic($isPublic);
            $view->setIsDefault($isDefault);
            $view->setQuery($query);

            // Update favoredBy if provided.
            if ($favoredBy !== null) {
                $view->setFavoredBy($favoredBy);
            }

            return $this->viewMapper->update($view);
        } catch (Exception $e) {
            $this->logger->error(message: 'Error updating view: '.$e->getMessage());
            throw $e;
        }//end try

    }//end update()


    /**
     * Delete a view by ID.
     *
     * @param int|string $id    The ID of the view to delete
     * @param string     $owner The owner user ID (for access control)
     *
     * @return void
     *
     * @throws Exception If deletion fails
     */
    public function delete(int | string $id, string $owner): void
    {
        try {
            $view = $this->find($id, $owner);
            $this->viewMapper->delete($view);
        } catch (Exception $e) {
            $this->logger->error(message: 'Error deleting view: '.$e->getMessage());
            throw $e;
        }

    }//end delete()


    /**
     * Clear default flag for all views of a user.
     *
     * @param string $owner The owner user ID
     *
     * @return void
     */
    private function clearDefaultForUser(string $owner): void
    {
        $views = $this->viewMapper->findAll($owner);
        foreach ($views as $view) {
            if ($view->getOwner() === $owner && $view->getIsDefault() === true) {
                $view->setIsDefault(false);
                $this->viewMapper->update($view);
            }
        }

    }//end clearDefaultForUser()


}//end class
