# Full Text Search NextCloud App(s)

## Overview

This document provides some information about the NextCloud Full Text Search App(s) and a step-by-step plan how to set this up on your local machine with the use of ElasticSearch as search platform.

## Local Setup Steps

1. Open a command-line interface (CLI), such as:
	- **Windows:** Command Prompt (`cmd`), PowerShell, or Windows Terminal.
	- **Linux/macOS:** Terminal.
2. Navigate to your local Nextcloud repository (where a docker-compose.yml file is present):
   ```sh
   cd {route to your local NC repo}
   ```
3. Start the necessary Docker containers:
   ```sh
   docker-compose up nextcloud proxy elasticsearch
   ```
4. In the Nextcloud front-end, go to **NC Apps > Search** and install the following three apps:
	- **Full text search Files**
	- **Full text search Elastic**
	- **Full text search**
5. Under **Administrator settings**, go to **Full text search** in the sidebar.
6. Under **General**, configure the following:
	- **Search Platform:** Set to **"Elasticsearch"**.
	- **Navigation Icon:** Check this option.
7. Under **Elastic Search**, set the following:
	- **Address of the Servlet:**
	  ```
	  http://elastic:elastic@elasticsearch:9200
	  ```
	- **Index:**
	  ```
	  my_index
	  ```
	- **[Advanced] Analyzer tokenizer:**
	  ```
	  standard
	  ```
8. Under **Files**, configure the following
	- **Check all checkboxes:**
      - Local Files
      - Group Folders
      - Extract PDF
      - Extract Office & Open Files
	- **Maximum file size:** Set your prefered maximum file size (at least **64** is recommended).
9. Add some files to Nextcloud in the Files tab of NextCloud.
10. Run the indexing command in the `master-nextcloud-1` container in Docker Desktop:
	```sh
	sudo -u www-data php ./occ fulltextsearch:index
	```
11. Open the **search app** and search for files based on the text inside them.
