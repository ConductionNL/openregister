---
title: Making Notes Stick
sidebar_position: 4
---

import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Note Taking in Open Register

## Note Object: A Tale of Digital Sticky Notes

Let's talk about notes - those digital breadcrumbs we leave everywhere in our systems. Whether you're a government employee making case notes, a manager jotting down meeting minutes, or a citizen service representative documenting a phone call, we all need to write things down. It's a fundamental human need that's followed us from paper notebooks into the digital age.

### The Quest for the Perfect Note

In our journey to design the perfect note system, we looked at how different platforms handle note-taking:

- **Microsoft OneNote** with its rich formatting and organizational hierarchy
- **Google Keep** focusing on simplicity and quick capture
- **Evernote** balancing features with usability
- **Schema.org's Comment type** providing a web-standard approach
- **Nextcloud Notes** offering an open-source perspective

Each system had its strengths, but they all shared common elements: a title, content, timestamps, and some way to organize notes. We wanted to create something that could work with all of these systems - a note that could live in your phone's notes app, sync to your office OneNote, or appear in your government case management system.

### The Magic of "About"

The real breakthrough came when we discovered Schema.org's "about" property. It's a simple but powerful concept: every note can be "about" something else. In technical terms, it's a URI or UUID pointing to another object in the system.

For example:

- A case worker can create a note 'about' a specific case
- A manager can write meeting minutes 'about' a project
- A support agent can log a conversation 'about' a customer

### Implementation in Open Register

In Open Register, we've implemented notes as first-class objects with the following key features:

1. **Rich Text Support**: Notes can contain formatted text, lists, and links using Markdown
2. **Attachments**: Files can be added to notes for comprehensive documentation
3. **Relationships**: Notes can be linked to any other object in the system
4. **Version History**: All changes to notes are tracked and can be reviewed
5. **Access Control**: Notes inherit permissions from their parent objects

### Technical Implementation

The note object schema includes:

<Tabs>
<TabItem value='json' label='JSON Schema'>

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "title": "Note",
  "description": "A note or comment about something",
  "required": ["title", "content"],
  "properties": {
    "id": {
      "type": "string",
      "description": "Unique identifier for the note"
    },
    "title": {
      "type": "string",
      "description": "Title of the note"
    },
    "content": {
      "type": "string",
      "description": "Content of the note in Markdown format"
    },
    "about": {
      "type": "string",
      "format": "uri",
      "description": "URI or UUID of the object this note is about"
    },
    "author": {
      "type": "string",
      "description": "Author of the note"
    },
    "dateCreated": {
      "type": "string",
      "format": "date-time",
      "description": "Date and time when the note was created"
    },
    "dateModified": {
      "type": "string",
      "format": "date-time",
      "description": "Date and time when the note was last modified"
    },
    "tags": {
      "type": "array",
      "items": {
        "type": "string"
      },
      "description": "Tags or keywords associated with the note"
    },
    "attachments": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "id": {
            "type": "string"
          },
          "name": {
            "type": "string"
          },
          "contentType": {
            "type": "string"
          },
          "url": {
            "type": "string",
            "format": "uri"
          }
        }
      },
      "description": "Files attached to the note"
    }
  }
}
```

</TabItem>
<TabItem value='example' label='Example Note'>

```json
{
  "id": "note-123456",
  "title": "Meeting with Project Team",
  "content": "# Discussion Points\n\n- Timeline review\n- Budget concerns\n- Resource allocation\n\n## Action Items\n\n1. John to update project plan by Friday\n2. Sarah to contact vendors about pricing\n3. Team to review resource requirements",
  "about": "project-789012",
  "author": "user-345678",
  "dateCreated": "2023-05-15T10:30:00Z",
  "dateModified": "2023-05-15T11:45:00Z",
  "tags": ["meeting", "project", "action-items"],
  "attachments": [
    {
      "id": "attachment-901234",
      "name": "Project Timeline.pdf",
      "contentType": "application/pdf",
      "url": "/api/files/attachment-901234"
    }
  ]
}
```

</TabItem>
</Tabs>

## Using Notes in Your Application

### Creating a Note

Notes can be created through the API or the user interface. When creating a note, you'll need to specify at minimum:

1. A title
2. Content (in Markdown format)
3. The 'about' reference (what the note is about)

<Tabs>
<TabItem value='api' label='API Request'>

```json
POST /api/objects
{
  "register": "notes-register",
  "schema": "note-schema",
  "object": {
    "title": "Customer Follow-up",
    "content": "Called customer regarding their recent support ticket. They confirmed the issue is resolved.",
    "about": "customer-567890"
  }
}
```

</TabItem>
<TabItem value='ui' label='User Interface'>

The Open Register UI provides a note editor with:

- A title field
- A rich text editor with Markdown support
- An 'about' selector to choose what the note relates to
- File upload capabilities for attachments
- Tag selection

</TabItem>
</Tabs>

### Retrieving Notes About an Object

One of the most powerful features is the ability to retrieve all notes about a specific object:

<Tabs>
<TabItem value='api' label='API Request'>

```json
GET /api/objects?register=notes-register&schema=note-schema&filter={"about":"customer-567890"}
```

</TabItem>
<TabItem value='ui' label='User Interface'>

In the Open Register UI, notes are displayed in the context of their related objects. When viewing any object, its associated notes appear in a dedicated "Notes" tab or section.

</TabItem>
</Tabs>

## Use Cases

### Case Management

In a government case management system, notes are essential for documenting interactions, decisions, and progress:

- **Case Workers** can document client interactions
- **Supervisors** can add review notes
- **Specialists** can provide expert input
- **Clients** can even add their own notes in self-service portals

### Project Management

For project teams, notes serve multiple purposes:

- **Meeting Minutes** capture discussions and decisions
- **Status Updates** document progress
- **Issue Notes** track problems and resolutions
- **Decision Records** preserve the reasoning behind choices

### Customer Relationship Management

In CRM systems, notes help maintain comprehensive customer histories:

- **Support Interactions** document customer issues and resolutions
- **Sales Notes** track conversations with prospects
- **Account Management** records important customer preferences
- **Internal Notes** share insights between team members

## Best Practices

### Writing Effective Notes

1. **Be Specific**: Include relevant details, dates, and names
2. **Structure Content**: Use headings, lists, and formatting to organize information
3. **Focus on Facts**: Distinguish between observations and interpretations
4. **Include Next Steps**: Note any follow-up actions required
5. **Be Concise**: Keep notes clear and to the point

### Organizational Strategies

1. **Consistent Tagging**: Develop a standard set of tags for your organization
2. **Linking Related Notes**: Use the 'about' property to create relationships
3. **Regular Reviews**: Periodically review and clean up notes
4. **Template Usage**: Create templates for common note types

## Conclusion

Notes in Open Register bridge the gap between structured data and the messy reality of human communication. By implementing notes as first-class objects with rich relationships to other entities, we've created a flexible system that adapts to how people naturally work while maintaining the benefits of structured data.

Whether you're implementing a case management system, a project tracking tool, or a customer relationship platform, the note object provides a powerful way to capture, organize, and retrieve the human side of your digital processes.