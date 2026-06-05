<?php

/**
 * OpenRegister SourceUpdatedEvent
 *
 * This file contains the event class dispatched when a source is updated
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

use OCA\OpenRegister\Db\Source;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a source is updated.
 */
class SourceUpdatedEvent extends Event
{

    /**
     * The updated source state.
     *
     * @var Source The source after update.
     */
    private Source $newSource;

    /**
     * The previous source state.
     *
     * @var Source The source before update.
     */
    private Source $oldSource;

    /**
     * Constructor for SourceUpdatedEvent.
     *
     * @param Source $newSource The source after update.
     * @param Source $oldSource The source before update.
     *
     * @return void
     */
    public function __construct(Source $newSource, Source $oldSource)
    {
        parent::__construct();
        $this->newSource = $newSource;
        $this->oldSource = $oldSource;
    }//end __construct()

    /**
     * Get the updated source.
     *
     * @return Source The source after update.
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getSource(): Source
    {
        return $this->newSource;
    }//end getSource()

    /**
     * Get the new source state.
     *
     * @return Source The source after update.
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getNewSource(): Source
    {
        return $this->newSource;
    }//end getNewSource()

    /**
     * Get the old source state.
     *
     * @return Source The source before update.
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getOldSource(): Source
    {
        return $this->oldSource;
    }//end getOldSource()
}//end class
