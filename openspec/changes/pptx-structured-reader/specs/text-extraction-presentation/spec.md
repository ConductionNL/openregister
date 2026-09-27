## Purpose

Reads a PowerPoint deck into structured slides, so a consuming app can turn one deck into one lesson draft with a block per slide. Each slide keeps its order, title, body text, speaker notes and image references, which flat search text loses.

@e2e exclude Backend PHP document reader (OOXML package parsing for slide order, titles, body, notes, images and input bounds) with no OpenRegister UI surface; exercised by PHPUnit on decks built inside the test. Covered by PHPUnit.

## ADDED Requirements

### Requirement: Slides come back in presentation order (REQ-PPTX-001)

The extractor SHALL return the slides in the order the deck presents them, which is the order of the presentation's slide list, not the order of the slide files inside the package. Each slide SHALL carry its 1-based position as `number` and a `hidden` flag that is true when the deck hides that slide.

#### Scenario: Slide files named out of order still come back in deck order

- **GIVEN** a deck whose slide list presents `slide2.xml` first and `slide1.xml` second
- **WHEN** the deck is extracted
- **THEN** the first returned slide has `number` 1 and carries the content of `slide2.xml`
- **AND** the second returned slide carries the content of `slide1.xml`

#### Scenario: A hidden slide is returned and flagged

- **GIVEN** a deck whose third slide is hidden
- **WHEN** the deck is extracted
- **THEN** the third returned slide has `hidden` true and its content is still returned

### Requirement: Each slide carries its title and its body text in shape order (REQ-PPTX-002)

Each slide SHALL carry `title`: the text of its title or centred-title placeholder, or an empty string when it has none. Each slide SHALL carry `body`: the non-empty paragraphs of every other text-bearing shape, in the order the shapes appear on the slide. Text inside grouped shapes and inside table cells SHALL be included in that order. Slide number, date, header and footer placeholders are page furniture and SHALL NOT be included. Runs within one paragraph SHALL be joined into one string with whitespace collapsed.

#### Scenario: Title and body are separated

- **GIVEN** a slide with the title "Fotosynthese" and a text box holding the paragraphs "Planten maken voedsel" and "Licht, water en CO2"
- **WHEN** the deck is extracted
- **THEN** the slide's `title` is "Fotosynthese"
- **AND** its `body` is `["Planten maken voedsel", "Licht, water en CO2"]`
- **AND** a slide-number placeholder on the same slide does not appear in `body`

#### Scenario: Grouped shapes and table cells keep their place

- **GIVEN** a slide with a text box "Eerst", then a group holding a text box "In de groep", then a table with cells "Cel A" and "Cel B"
- **WHEN** the deck is extracted
- **THEN** the slide's `body` is `["Eerst", "In de groep", "Cel A", "Cel B"]`

#### Scenario: A paragraph split into runs is one string

- **GIVEN** a paragraph made of the runs "Water " and "kookt"
- **WHEN** the deck is extracted
- **THEN** the body holds the single entry "Water kookt"

### Requirement: Each slide carries its speaker notes (REQ-PPTX-003)

Each slide SHALL carry `notes`: the text of every text shape on that slide's notes page, in shape order, paragraphs joined by a newline, or an empty string when the slide has no notes. The notes body placeholder (as PowerPoint writes it) and a plain text box (as LibreOffice writes it) SHALL both count. The slide image, slide number, header, footer and date placeholders on the notes page SHALL NOT be included.

#### Scenario: Notes leave out the page furniture

- **GIVEN** a slide whose notes page holds a slide-number placeholder "3" and a notes body "Vraag eerst wat ze al weten."
- **WHEN** the deck is extracted
- **THEN** the slide's `notes` is "Vraag eerst wat ze al weten."

#### Scenario: Notes written as a plain text box are read

- **GIVEN** a slide whose notes page holds its notes in a text box that is not a placeholder
- **WHEN** the deck is extracted
- **THEN** the slide's `notes` holds that text

#### Scenario: A slide without a notes page has empty notes

- **GIVEN** a slide with no notes page
- **WHEN** the deck is extracted
- **THEN** the slide's `notes` is an empty string

### Requirement: Each slide carries its image references in shape order (REQ-PPTX-004)

Each slide SHALL carry `images`: one entry per picture on the slide, in shape order. Each entry SHALL give `target` (the image's path inside the package, resolved from the slide's relationships, or the URL for a linked image), `external` (true for a linked image), `name` and `description` (the picture's alt text, or an empty string). The extractor SHALL NOT return the image bytes.

#### Scenario: An embedded picture is referenced by its package path and alt text

- **GIVEN** a slide with a picture named "Blad" whose alt text is "Een blad in de zon", embedded as `../media/image1.png`
- **WHEN** the deck is extracted
- **THEN** the slide's `images` is `[{target: "ppt/media/image1.png", external: false, name: "Blad", description: "Een blad in de zon"}]`

### Requirement: A deck that cannot be read degrades to no result (REQ-PPTX-005)

The extractor SHALL return `null`, not throw, when the input is not a readable deck: corrupt or non-zip bytes, a package without a presentation part, a deck with no slides, or a format it does not read (legacy binary `.ppt`, `.odp`). It SHALL log the failure with the file id and MIME type, plus the exception class when one was thrown, and SHALL NOT log any document content. A missing zip extension on the server is a deployment error and SHALL throw.

#### Scenario: Garbage bytes return null without leaking content

- **GIVEN** a file with a pptx MIME type whose bytes are not a zip package
- **WHEN** it is extracted
- **THEN** the result is `null`
- **AND** the logged error carries no part of the file's bytes

#### Scenario: A legacy binary deck is not read

- **GIVEN** a file with MIME type `application/vnd.ms-powerpoint`
- **WHEN** it is extracted
- **THEN** the result is `null`

### Requirement: Hostile input is bounded (REQ-PPTX-006)

The extractor SHALL refuse any XML part that declares a DOCTYPE. It SHALL read each part only up to a fixed size cap and treat a larger part as unreadable. It SHALL stop after a fixed maximum number of slides and then set `truncated` to true on the result. It SHALL stop descending nested groups past a fixed depth.

#### Scenario: A DOCTYPE in a slide part is refused

- **GIVEN** a deck whose slide part declares a DOCTYPE with an entity
- **WHEN** the deck is extracted
- **THEN** that slide's text is not read and no entity is expanded

#### Scenario: A deck within the limits is not truncated

- **GIVEN** a deck with three slides
- **WHEN** the deck is extracted
- **THEN** `truncated` is false

### Requirement: The supported formats can be asked for (REQ-PPTX-007)

A caller SHALL be able to ask, before extracting, whether a file is a format the extractor reads, by MIME type or, when the MIME type is generic, by file extension. The supported formats are `.pptx`, `.pptm` and `.ppsx`.

#### Scenario: A pptx with a generic MIME type is recognised by extension

- **GIVEN** MIME type `application/octet-stream` and file name `les-3.pptx`
- **WHEN** support is asked for
- **THEN** the answer is yes

#### Scenario: A legacy ppt is not supported

- **GIVEN** MIME type `application/vnd.ms-powerpoint` and file name `les-3.ppt`
- **WHEN** support is asked for
- **THEN** the answer is no
