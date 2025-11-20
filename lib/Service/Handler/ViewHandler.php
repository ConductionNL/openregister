<?php
/**
 * OpenRegister View Handler
 *
 * This file contains the handler class for View entity import/export operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handler
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Handler;

use Exception;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use Psr\Log\LoggerInterface;

/**
 * Class ViewHandler
 *
 * Handles import and export operations for View entities.
 *
 * @package OCA\OpenRegister\Service\Handler
 */
class ViewHandler
{

    /**
     * View mapper instance.
     *
     * @var ViewMapper The view mapper instance.
     */
    private ViewMapper $viewMapper;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param ViewMapper      $viewMapper The view mapper instance
     * @param LoggerInterface $logger     The logger instance
     */
    public function __construct(ViewMapper $viewMapper, LoggerInterface $logger)
    {
        $this->viewMapper = $viewMapper;
        $this->logger     = $logger;

    }//end __construct()


    /**
     * Export a view to array format
     *
     * @param View $view The view to export
     *
     * @return array The exported view data
     */
    public function export(View $view): array
    {
        $viewArray = $view->jsonSerialize();
        unset($viewArray['id'], $viewArray['uuid']);
        return $viewArray;

    }//end export()


    /**
     * Import a view from configuration data
     *
     * @param array       $data  The view data
     * @param string|null $owner The owner of the view
     *
     * @return View The imported view or null if skipped
     *
     * @throws Exception If import fails
     */
    public function import(array $data, ?string $owner=null): View
    {
        try {
            unset($data['id'], $data['uuid']);

            // Check if view already exists by name.
            $existingViews = $this->viewMapper->findAll();
            $existingView  = null;
            foreach ($existingViews as $view) {
                if ($view->getName() === $data['name']) {
                    $existingView = $view;
                    break;
                }
            }

            if ($existingView !== null) {
                // Update existing view.
                $existingView->hydrate($data);
                if ($owner !== null) {
                    $existingView->setOwner($owner);
                }

                return $this->viewMapper->update($existingView);
            }

            // Create new view.
            $view = new View();
            $view->hydrate($data);
            if ($owner !== null) {
                $view->setOwner($owner);
            }

            return $this->viewMapper->insert($view);
        } catch (Exception $e) {
            $this->logger->error('Failed to import view: '.$e->getMessage());
            throw $e;
        }//end try

    }//end import()


}//end class
