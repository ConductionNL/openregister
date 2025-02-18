 # Developer Guide

## Overview

This is a Nextcloud application. Below you will find important information about the project structure and where to find various components.

## Project Structure

- **appinfo/routes.php**: Defines the routes for the application. For example:
  ```php
  <?php

  return [
    'routes' => [
      ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
    ],
  ];
  ```
- **lib**: Contains all the PHP code for the application.
- **src**: Contains all the Vue.js code for the application.
- **docs**: Contains documentation files.

## Schemas

Schemas are defined using JSON Schema format. These schemas are used to validate the structure of data within the application.

## Additional Information

For more detailed information on how to contribute to this project, please refer to the other documentation files in the `docs` folder.