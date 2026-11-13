## ADDED Requirements

### Requirement: A configuration package is published to a GitLab repository

An administrator SHALL be able to publish a configuration package to a GitLab repository, as
they can to a GitHub repository. The package SHALL be written to the chosen project, branch and
path, and the configuration SHALL remember that GitLab source so a later import finds it.

#### Scenario: An administrator publishes to GitLab
- **GIVEN** a configuration `afval-meldingen` and a GitLab project the administrator may write to
- **WHEN** the administrator publishes it to that project on branch `main` at `configurations/afval-meldingen.json`
- **THEN** the file exists in the project with the exported configuration
- **AND** the configuration records the GitLab project, branch and path as its source

#### Scenario: A second publish updates the same file
- **GIVEN** a configuration already published to GitLab
- **WHEN** the administrator publishes it again after a change
- **THEN** the file in the project holds the new content and no second file is made
