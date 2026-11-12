# bag-address-lookup Specification (delta)

## ADDED Requirements

### Requirement: The BAG register holds the national address set (REQ-BAG-001)

OpenRegister MUST offer an admin command and a monthly background job that load the BAG `nummeraanduiding` set, with street and town names, from a configurable PDOK download into the `bag` register. The load MUST be an upsert keyed on `identificatie`, MUST mark withdrawn addresses with their BAG status, and MUST write one audit line with the counts of created, updated and withdrawn records.

#### Scenario: a first load fills the register
- **WHEN** an administrator runs the load against a sample extract of 10,000 addresses
- **THEN** the `bag` register holds 10,000 `nummeraanduiding` objects and one audit line names the counts
- @e2e exclude admin command; covered by a PHPUnit DB test with a sample extract

#### Scenario: a monthly refresh changes only what changed
- **WHEN** the refresh runs with an extract where one address got a new street name and one was withdrawn
- **THEN** one object is updated, one is marked withdrawn, and no other object is written
- @e2e exclude background job; covered by a PHPUnit DB test

### Requirement: An address is found by postcode and house number (REQ-BAG-002)

OpenRegister MUST store postcode, house number, letter and addition normalised, MUST index postcode with house number, and MUST answer a lookup by postcode and house number, optionally narrowed by letter and addition, with street, town, nummeraanduiding and verblijfsobject. A lookup MUST skip withdrawn addresses. With only postcode and number it MUST return every letter and addition at that number. The lookup MUST be callable through a PHP service and through a public, throttled read endpoint.

#### Scenario: a resident types her postcode with a space and in lower case
- **WHEN** the lookup is asked for "2611 ab" number 12
- **THEN** it answers Lindelaan 12, 2611AB Zuiddrecht with its nummeraanduiding id
- @e2e exclude backend lookup; covered by PHPUnit and a Newman call on the public endpoint

#### Scenario: a number with several additions
- **WHEN** the lookup is asked for a postcode and number that has additions A and B
- **THEN** both addresses are returned so the form can ask which one
- @e2e exclude backend lookup; covered by PHPUnit

#### Scenario: an address that does not exist
- **WHEN** the endpoint is asked for a postcode and number with no match
- **THEN** it answers 404 and no outside call is made
- @e2e exclude backend endpoint; covered by Newman

#### Scenario: an anonymous client asks too often
- **WHEN** one client sends more than 60 lookups in a minute
- **THEN** further lookups are throttled
- @e2e exclude backend throttle; covered by PHPUnit
