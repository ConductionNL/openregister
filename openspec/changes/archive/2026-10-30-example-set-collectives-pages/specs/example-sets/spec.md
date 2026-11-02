## ADDED Requirements

### Requirement: A fragment may write Collectives pages

A fragment MAY declare `collectivesPages`. Each row SHALL name `collective`, `title`, `content` and `owner`, and MAY give `shareWith`, a list of Nextcloud groups. The owner SHALL be an account the set creates or adopts. On install the set SHALL:
- find the collective by name, or create it with the groups in its team;
- find the page by title in it, or create it with the content.

A second install SHALL keep the page. Remove SHALL delete the page the set created, and the collective the set created, with its team. Without Collectives the row SHALL be reported as not written.

#### Scenario: a work instruction
- **GIVEN** a row for collective "Woo en informatie", page "Hoe we Woo-verzoeken behandelen", shared with the Woo team's group
- **WHEN** the set is installed
- **THEN** the page exists with its content, and the case type's `knowledgeBasePage` "Woo en informatie/Hoe we Woo-verzoeken behandelen" opens it

#### Scenario: an existing collective
- **WHEN** the collective already exists
- **THEN** the page goes in it, and remove leaves the collective and its other pages
