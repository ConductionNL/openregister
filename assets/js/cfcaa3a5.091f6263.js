"use strict";(self.webpackChunkopen_catalogi_docs=self.webpackChunkopen_catalogi_docs||[]).push([[458],{187:(e,n,i)=>{i.r(n),i.d(n,{assets:()=>a,contentTitle:()=>l,default:()=>d,frontMatter:()=>o,metadata:()=>s,toc:()=>c});const s=JSON.parse('{"id":"Core/relationships","title":"Concept Relationships","description":"One of the most powerful aspects of Open Register is how its core concepts interact with each other. Understanding these relationships is key to effectively designing and using the system.","source":"@site/docs/Core/relationships.md","sourceDirName":"Core","slug":"/Core/relationships","permalink":"/docs/Core/relationships","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/openregister/tree/main/website/docs/Core/relationships.md","tags":[],"version":"current","sidebarPosition":8,"frontMatter":{"title":"Concept Relationships","sidebar_position":8},"sidebar":"tutorialSidebar","previous":{"title":"Events","permalink":"/docs/Core/events"},"next":{"title":"overview","permalink":"/docs/Core/overview"}}');var t=i(4848),r=i(8453);const o={title:"Concept Relationships",sidebar_position:8},l="Relationships Between Core Concepts",a={},c=[{value:"Relationship Overview",id:"relationship-overview",level:2},{value:"Register-Schema Relationship",id:"register-schema-relationship",level:2},{value:"How Registers Use Schemas",id:"how-registers-use-schemas",level:3},{value:"Example",id:"example",level:3},{value:"Design Considerations",id:"design-considerations",level:3},{value:"Register-Object Relationship",id:"register-object-relationship",level:2},{value:"How Registers Contain Objects",id:"how-registers-contain-objects",level:3},{value:"Example",id:"example-1",level:3},{value:"Design Considerations",id:"design-considerations-1",level:3},{value:"Schema-Object Relationship",id:"schema-object-relationship",level:2},{value:"How Schemas Define Objects",id:"how-schemas-define-objects",level:3},{value:"Example",id:"example-2",level:3},{value:"Schema Evolution and Objects",id:"schema-evolution-and-objects",level:3},{value:"Object-File Relationship",id:"object-file-relationship",level:2},{value:"How Objects Contain Files",id:"how-objects-contain-files",level:3},{value:"Example",id:"example-3",level:3},{value:"Design Considerations",id:"design-considerations-2",level:3},{value:"Schema-File Relationship",id:"schema-file-relationship",level:2},{value:"How Schemas Define Files",id:"how-schemas-define-files",level:3},{value:"Example",id:"example-4",level:3},{value:"Design Considerations",id:"design-considerations-3",level:3},{value:"Entity-Event Relationships",id:"entity-event-relationships",level:2},{value:"How Entities Trigger Events",id:"how-entities-trigger-events",level:3},{value:"Example",id:"example-5",level:3},{value:"Design Considerations",id:"design-considerations-4",level:3},{value:"Source Relationships",id:"source-relationships",level:2},{value:"How Sources Store Data",id:"how-sources-store-data",level:3},{value:"Example",id:"example-6",level:3},{value:"Design Considerations",id:"design-considerations-5",level:3},{value:"Object-Object Relationships",id:"object-object-relationships",level:2},{value:"How Objects Relate to Each Other",id:"how-objects-relate-to-each-other",level:3},{value:"Example",id:"example-7",level:3},{value:"Types of Relationships",id:"types-of-relationships",level:3},{value:"Design Considerations",id:"design-considerations-6",level:3},{value:"Practical Examples",id:"practical-examples",level:2},{value:"Example 1: Document Management System with Events",id:"example-1-document-management-system-with-events",level:3},{value:"Example 2: Product Catalog System with Integration",id:"example-2-product-catalog-system-with-integration",level:3},{value:"Best Practices for Managing Relationships",id:"best-practices-for-managing-relationships",level:2},{value:"Conclusion",id:"conclusion",level:2}];function h(e){const n={code:"code",h1:"h1",h2:"h2",h3:"h3",header:"header",li:"li",ol:"ol",p:"p",pre:"pre",strong:"strong",ul:"ul",...(0,r.R)(),...e.components};return(0,t.jsxs)(t.Fragment,{children:[(0,t.jsx)(n.header,{children:(0,t.jsx)(n.h1,{id:"relationships-between-core-concepts",children:"Relationships Between Core Concepts"})}),"\n",(0,t.jsx)(n.p,{children:"One of the most powerful aspects of Open Register is how its core concepts interact with each other. Understanding these relationships is key to effectively designing and using the system."}),"\n",(0,t.jsx)(n.h2,{id:"relationship-overview",children:"Relationship Overview"}),"\n",(0,t.jsx)(n.p,{children:"The core entities in Open Register - Registers, Schemas, Objects, Files, Sources, and Events - form an interconnected system:"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-mermaid",children:"graph TD\n    Register[Registers] --\x3e|contain| Object[Objects]\n    Register --\x3e|support| Schema[Schemas]\n    Object --\x3e|conform to| Schema\n    Register --\x3e|stored in| Source[Sources]\n    Object --\x3e|stored in| Source\n    Schema --\x3e|stored in| Source\n    Object --\x3e|relate to| Object\n    Object --\x3e|has| File[Files]\n    File --\x3e|stored in| Source\n    Schema --\x3e|defines| File\n    Object --\x3e|trigger| Event[Events]\n    Register --\x3e|trigger| Event\n    Schema --\x3e|trigger| Event\n    File --\x3e|trigger| Event\n"})}),"\n",(0,t.jsx)(n.h2,{id:"register-schema-relationship",children:"Register-Schema Relationship"}),"\n",(0,t.jsx)(n.p,{children:"Registers and schemas have a many-to-many relationship:"}),"\n",(0,t.jsx)(n.h3,{id:"how-registers-use-schemas",children:"How Registers Use Schemas"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["A register defines which schemas it supports through its ",(0,t.jsx)(n.code,{children:"schemas"})," property"]}),"\n",(0,t.jsx)(n.li,{children:"This property contains an array of schema IDs"}),"\n",(0,t.jsx)(n.li,{children:"Only objects conforming to one of these schemas can be stored in the register"}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"example",children:"Example"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-json",children:'// Register definition\n{\n  "id": "person-register",\n  "title": "Person Register",\n  "schemas": ["person", "address", "contact-details"],\n  // other properties...\n}\n'})}),"\n",(0,t.jsx)(n.p,{children:'This register supports three schemas: "person", "address", and "contact-details".'}),"\n",(0,t.jsx)(n.h3,{id:"design-considerations",children:"Design Considerations"}),"\n",(0,t.jsx)(n.p,{children:"When designing the relationship between registers and schemas:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Group Related Schemas"}),": Include schemas that logically belong together in the same register"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Consider Access Control"}),": Schemas in the same register often share similar access patterns"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Balance Specificity and Flexibility"}),": Too many schemas can make a register unfocused, while too few can limit its usefulness"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"register-object-relationship",children:"Register-Object Relationship"}),"\n",(0,t.jsx)(n.p,{children:"Registers and objects have a one-to-many relationship:"}),"\n",(0,t.jsx)(n.h3,{id:"how-registers-contain-objects",children:"How Registers Contain Objects"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["Each object belongs to exactly one register (specified by its ",(0,t.jsx)(n.code,{children:"register"})," property)"]}),"\n",(0,t.jsx)(n.li,{children:"A register can contain many objects"}),"\n",(0,t.jsx)(n.li,{children:"Objects in a register must conform to one of the register's supported schemas"}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"example-1",children:"Example"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-json",children:'// Object definition\n{\n  "id": "person-12345",\n  "register": "person-register",\n  "schema": "person",\n  // other properties...\n}\n'})}),"\n",(0,t.jsx)(n.p,{children:'This object belongs to the "person-register" and conforms to the "person" schema.'}),"\n",(0,t.jsx)(n.h3,{id:"design-considerations-1",children:"Design Considerations"}),"\n",(0,t.jsx)(n.p,{children:"When designing the relationship between registers and objects:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Logical Grouping"}),": Group objects that are commonly accessed together in the same register"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Performance"}),": Consider query patterns when deciding which objects go in which register"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Scalability"}),": Plan for how registers will grow over time"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"schema-object-relationship",children:"Schema-Object Relationship"}),"\n",(0,t.jsx)(n.p,{children:"Schemas and objects have a one-to-many relationship:"}),"\n",(0,t.jsx)(n.h3,{id:"how-schemas-define-objects",children:"How Schemas Define Objects"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["Each object conforms to exactly one schema (specified by its ",(0,t.jsx)(n.code,{children:"schema"})," property)"]}),"\n",(0,t.jsx)(n.li,{children:"A schema can be used by many objects"}),"\n",(0,t.jsx)(n.li,{children:"The schema defines the structure, validation rules, and relationships for the object"}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"example-2",children:"Example"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-json",children:'// Schema definition\n{\n  "id": "person",\n  "properties": {\n    "firstName": { "type": "string" },\n    "lastName": { "type": "string" },\n    // other properties...\n  }\n}\n\n// Object data conforming to the schema\n{\n  "firstName": "John",\n  "lastName": "Doe"\n}\n'})}),"\n",(0,t.jsx)(n.h3,{id:"schema-evolution-and-objects",children:"Schema Evolution and Objects"}),"\n",(0,t.jsx)(n.p,{children:"When schemas evolve, it affects objects:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Minor Changes"}),": Adding optional fields or relaxing constraints typically doesn't affect existing objects"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Major Changes"}),": Adding required fields or changing field types may require updating existing objects"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Versioning"}),": Schema versioning helps manage these changes over time"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"object-file-relationship",children:"Object-File Relationship"}),"\n",(0,t.jsx)(n.p,{children:"Objects and files have a one-to-many relationship:"}),"\n",(0,t.jsx)(n.h3,{id:"how-objects-contain-files",children:"How Objects Contain Files"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["Files are attached to objects through the object's ",(0,t.jsx)(n.code,{children:"files"})," property"]}),"\n",(0,t.jsx)(n.li,{children:"An object can have multiple files"}),"\n",(0,t.jsx)(n.li,{children:"Files inherit permissions from their parent object"}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"example-3",children:"Example"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-json",children:'// Object with files\n{\n  "id": "contract-12345",\n  "files": [\n    {\n      "id": "file-67890",\n      "name": "contract.pdf",\n      "contentType": "application/pdf",\n      "size": 1245678,\n      "url": "/api/files/file-67890"\n    },\n    {\n      "id": "file-54321",\n      "name": "signature.jpg",\n      "contentType": "image/jpeg",\n      "size": 45678,\n      "url": "/api/files/file-54321"\n    }\n  ],\n  // other properties...\n}\n'})}),"\n",(0,t.jsx)(n.h3,{id:"design-considerations-2",children:"Design Considerations"}),"\n",(0,t.jsx)(n.p,{children:"When designing the relationship between objects and files:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"File Organization"}),": Consider how files should be organized and named"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Metadata"}),": Define what metadata should be stored with files"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Versioning"}),": Determine how file versions should be managed"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Access Control"}),": Plan how file permissions should be handled"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"schema-file-relationship",children:"Schema-File Relationship"}),"\n",(0,t.jsx)(n.p,{children:"Schemas can define expectations for file attachments:"}),"\n",(0,t.jsx)(n.h3,{id:"how-schemas-define-files",children:"How Schemas Define Files"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsx)(n.li,{children:"Schemas can specify file properties like allowed types and maximum sizes"}),"\n",(0,t.jsx)(n.li,{children:"Schemas can define required file attachments"}),"\n",(0,t.jsx)(n.li,{children:"Schemas can specify validation rules for file metadata"}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"example-4",children:"Example"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-json",children:'// Schema with file definitions\n{\n  "id": "contract",\n  "properties": {\n    "title": { "type": "string" },\n    "files": {\n      "type": "array",\n      "items": {\n        "type": "object",\n        "properties": {\n          "type": {\n            "type": "string",\n            "enum": ["contract", "signature", "attachment"]\n          },\n          "contentType": {\n            "type": "string",\n            "pattern": "^application/pdf|image/jpeg|image/png$"\n          },\n          "maxSize": {\n            "type": "integer",\n            "maximum": 10485760 // 10MB\n          }\n        }\n      },\n      "minItems": 1\n    }\n  },\n  "required": ["title", "files"]\n}\n'})}),"\n",(0,t.jsx)(n.h3,{id:"design-considerations-3",children:"Design Considerations"}),"\n",(0,t.jsx)(n.p,{children:"When designing the relationship between schemas and files:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Validation Rules"}),": Define appropriate validation rules for files"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Required Files"}),": Determine which files should be required"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"File Types"}),": Specify allowed file types and formats"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Size Limits"}),": Set appropriate size limits for different file types"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"entity-event-relationships",children:"Entity-Event Relationships"}),"\n",(0,t.jsx)(n.p,{children:"Events are triggered by and relate to other entities:"}),"\n",(0,t.jsx)(n.h3,{id:"how-entities-trigger-events",children:"How Entities Trigger Events"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsx)(n.li,{children:"Changes to objects, registers, schemas, and files trigger events"}),"\n",(0,t.jsx)(n.li,{children:"Events carry data about the entity and the change"}),"\n",(0,t.jsx)(n.li,{children:"Events enable other components to react to changes"}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"example-5",children:"Example"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-php",children:"// Event triggered by object creation\nclass ObjectCreatedEvent extends Event {\n    private ObjectEntity $object;\n\n    public function __construct(ObjectEntity $object) {\n        parent::__construct();\n        $this->object = $object;\n    }\n\n    public function getObject(): ObjectEntity {\n        return $this->object;\n    }\n}\n"})}),"\n",(0,t.jsx)(n.h3,{id:"design-considerations-4",children:"Design Considerations"}),"\n",(0,t.jsx)(n.p,{children:"When designing the relationship between entities and events:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Event Granularity"}),": Determine the right level of detail for events"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Event Data"}),": Include enough data in events for listeners to be useful"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Event Naming"}),": Use consistent naming conventions for events"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Event Documentation"}),": Document when events are triggered and what data they contain"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"source-relationships",children:"Source Relationships"}),"\n",(0,t.jsx)(n.p,{children:"Sources have relationships with all other entities:"}),"\n",(0,t.jsx)(n.h3,{id:"how-sources-store-data",children:"How Sources Store Data"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsx)(n.li,{children:"Sources provide the storage backend for registers, schemas, objects, and files"}),"\n",(0,t.jsx)(n.li,{children:"Different entities can use different sources"}),"\n",(0,t.jsx)(n.li,{children:"Source configuration affects performance, scalability, and reliability"}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"example-6",children:"Example"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-json",children:'// Register referencing a source\n{\n  "id": "person-register",\n  "source": "primary-source",\n  // other properties...\n}\n\n// Source definition\n{\n  "id": "primary-source",\n  "databaseUrl": "mongodb://localhost:27017/openregister",\n  "type": "mongodb",\n  // other properties...\n}\n'})}),"\n",(0,t.jsx)(n.h3,{id:"design-considerations-5",children:"Design Considerations"}),"\n",(0,t.jsx)(n.p,{children:"When designing source relationships:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Performance"}),": Match source types to access patterns"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Scalability"}),": Plan for growth in data volume"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Reliability"}),": Consider redundancy and backup requirements"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Security"}),": Isolate sensitive data in separate sources if needed"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"object-object-relationships",children:"Object-Object Relationships"}),"\n",(0,t.jsx)(n.p,{children:"Objects can have relationships with other objects:"}),"\n",(0,t.jsx)(n.h3,{id:"how-objects-relate-to-each-other",children:"How Objects Relate to Each Other"}),"\n",(0,t.jsxs)(n.ul,{children:["\n",(0,t.jsxs)(n.li,{children:["Objects can reference other objects through their ",(0,t.jsx)(n.code,{children:"relations"})," property"]}),"\n",(0,t.jsx)(n.li,{children:"These relationships can cross register boundaries"}),"\n",(0,t.jsx)(n.li,{children:"Relationships have a type that defines their meaning"}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"example-7",children:"Example"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-json",children:'// Object with relationships\n{\n  "id": "person-12345",\n  "relations": [\n    {\n      "type": "spouse",\n      "target": "person-67890"\n    },\n    {\n      "type": "employer",\n      "target": "organization-54321"\n    }\n  ],\n  // other properties...\n}\n'})}),"\n",(0,t.jsx)(n.h3,{id:"types-of-relationships",children:"Types of Relationships"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Hierarchical"}),": Parent-child relationships (e.g., department-employee)"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Associative"}),": Peer relationships (e.g., spouse, colleague)"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Compositional"}),": Whole-part relationships (e.g., product-component)"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Referential"}),": Simple references (e.g., author of a document)"]}),"\n"]}),"\n",(0,t.jsx)(n.h3,{id:"design-considerations-6",children:"Design Considerations"}),"\n",(0,t.jsx)(n.p,{children:"When designing object relationships:"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Relationship Types"}),": Define clear relationship types with specific meanings"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Bidirectionality"}),": Consider whether relationships need to be navigable in both directions"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Integrity"}),": Maintain referential integrity when objects are deleted"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Performance"}),": Consider query patterns when designing relationships"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"practical-examples",children:"Practical Examples"}),"\n",(0,t.jsx)(n.h3,{id:"example-1-document-management-system-with-events",children:"Example 1: Document Management System with Events"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-mermaid",children:"graph TD\n    DocumentRegister[Document Register] --\x3e|supports| DocumentSchema[Document Schema]\n    DocumentRegister --\x3e|contains| DocumentObject[Document Objects]\n    DocumentObject --\x3e|conform to| DocumentSchema\n    DocumentObject --\x3e|has| DocumentFile[Document Files]\n    DocumentFile --\x3e|stored in| FileSource[File Source]\n    DocumentObject --\x3e|has metadata| MetadataObject[Metadata Objects]\n    MetadataObject --\x3e|conform to| MetadataSchema[Metadata Schema]\n    DocumentRegister --\x3e|stored in| DocumentSource[Document Source]\n    DocumentObject --\x3e|triggers| ObjectEvents[Object Events]\n    DocumentFile --\x3e|triggers| FileEvents[File Events]\n    ObjectEvents --\x3e|listened by| WorkflowSystem[Workflow System]\n    FileEvents --\x3e|listened by| NotificationSystem[Notification System]\n"})}),"\n",(0,t.jsx)(n.h3,{id:"example-2-product-catalog-system-with-integration",children:"Example 2: Product Catalog System with Integration"}),"\n",(0,t.jsx)(n.pre,{children:(0,t.jsx)(n.code,{className:"language-mermaid",children:"graph TD\n    ProductRegister[Product Register] --\x3e|supports| ProductSchema[Product Schema]\n    ProductRegister --\x3e|supports| CategorySchema[Category Schema]\n    ProductRegister --\x3e|contains| ProductObject[Product Objects]\n    ProductObject --\x3e|conform to| ProductSchema\n    ProductObject --\x3e|belongs to| CategoryObject[Category Objects]\n    CategoryObject --\x3e|conform to| CategorySchema\n    ProductObject --\x3e|has| ProductImage[Product Images]\n    ProductImage --\x3e|stored in| MediaSource[Media Source]\n    ProductRegister --\x3e|stored in| ProductSource[Product Source]\n    ProductObject --\x3e|triggers| ProductEvents[Product Events]\n    ProductEvents --\x3e|listened by| ExternalSystem[External System]\n    ProductEvents --\x3e|listened by| SearchIndex[Search Index]\n"})}),"\n",(0,t.jsx)(n.h2,{id:"best-practices-for-managing-relationships",children:"Best Practices for Managing Relationships"}),"\n",(0,t.jsxs)(n.ol,{children:["\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Document Relationships"}),": Clearly document the relationships between entities"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Consistent Naming"}),": Use consistent naming conventions for relationship types"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Validate Relationships"}),": Ensure relationships reference valid entities"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Consider Performance"}),": Design relationships with query performance in mind"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Plan for Evolution"}),": Design relationships that can evolve over time"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Maintain Integrity"}),": Implement processes to maintain referential integrity"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"File Organization"}),": Develop clear strategies for organizing and managing files"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Event Design"}),": Design events to carry sufficient context for listeners"]}),"\n",(0,t.jsxs)(n.li,{children:[(0,t.jsx)(n.strong,{children:"Listener Independence"}),": Ensure event listeners can operate independently"]}),"\n"]}),"\n",(0,t.jsx)(n.h2,{id:"conclusion",children:"Conclusion"}),"\n",(0,t.jsx)(n.p,{children:"The relationships between Open Register's core concepts create a flexible yet structured system for managing data. By understanding these relationships, you can design effective data models that leverage the full power of the system while maintaining data quality and performance."})]})}function d(e={}){const{wrapper:n}={...(0,r.R)(),...e.components};return n?(0,t.jsx)(n,{...e,children:(0,t.jsx)(h,{...e})}):h(e)}},8453:(e,n,i)=>{i.d(n,{R:()=>o,x:()=>l});var s=i(6540);const t={},r=s.createContext(t);function o(e){const n=s.useContext(r);return s.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function l(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(t):e.components||t:o(e.components),s.createElement(r.Provider,{value:n},e.children)}}}]);