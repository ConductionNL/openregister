<?php

/**
 * OpenRegister ViewDeletedEvent
 *
 * This file contains the event class dispatched when a view is deleted
 * in the OpenRegister application.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
=======
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\View;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a view is deleted.
 */
class ViewDeletedEvent extends Event
{

    /**
     * The deleted view.
     *
     * @var View The view that was deleted.
     */
    private View $view;

    /**
     * Constructor for ViewDeletedEvent.
     *
     * @param View $view The view that was deleted.
     *
     * @return void
     */
    public function __construct(View $view)
    {
        parent::__construct();
        $this->view = $view;
    }//end __construct()

    /**
     * Get the deleted view.
     *
     * @return View The view that was deleted.
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getView(): View
    {
        return $this->view;
    }//end getView()
}//end class
