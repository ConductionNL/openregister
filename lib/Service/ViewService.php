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
 * Service class for managing views in the OpenRegister application.
 *
 * This service acts as a facade for view operations,
 * coordinating between ViewMapper and business logic.
 */
class ViewService
{



    /**
     * Find a view by ID.
     *
     * @param int|string $id    The ID of the view to find
     * @param string     $owner The owner user ID for access control
     *
     * @return View The found view
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple found
     * @throws \OCP\DB\Exception If database error occurs
     */
    public function find(int | string $id, string $owner): View
    {
        $view = $this->viewMapper->find($id);

        // Check if user has access to this view.
        if ($view->getOwner() !== $owner && $view->getIsPublic() === false) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('View not found or access denied');
        }

        return $view;

    }//end find()


    /**
     * Find all views accessible to a user.
     *
     * @param string $owner The owner user ID
     *
     * @return View[] Array of found views
     *
     * @psalm-return array<View>
     */
    public function findAll(string $owner): array
    {
        return $this->viewMapper->findAll(owner: $owner);

    }//end findAll()


    /**
     * Create a new view.
     *
     * @param string $name        The name of the view
     * @param string $description The description of the view
     * @param string $owner       The owner user ID
     * @param bool   $isPublic    Whether the view is public
     * @param bool   $isDefault   Whether the view is default
     * @param array  $query       The query parameters (registers, schemas, filters)
     *
     * @return View The created view
     *
     * @throws Exception If creation fails
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
            // If this is set as default, unset any existing default for this user.
            if ($isDefault === true) {
                $this->clearDefaultForUser($owner);
            }

            $view = new View();
            $view->setName($name);
            $view->setDescription($description);
            $view->setOwner($owner);
            $view->setIsPublic($isPublic);
            $view->setIsDefault($isDefault);
            $view->setQuery($query);
            $view->setFavoredBy([]);

            return $this->viewMapper->insert($view);
        } catch (Exception $e) {
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
