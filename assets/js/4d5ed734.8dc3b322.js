(self.webpackChunkopen_catalogi_docs=self.webpackChunkopen_catalogi_docs||[]).push([[327],{2441:()=>{},2869:(e,n,t)=>{"use strict";t.r(n),t.d(n,{assets:()=>a,contentTitle:()=>d,default:()=>h,frontMatter:()=>l,metadata:()=>s,toc:()=>c});const s=JSON.parse('{"id":"Features/events","title":"Core Features","description":"An overview of how core concepts in Open Register interact with each other.","source":"@site/docs/Features/events.md","sourceDirName":"Features","slug":"/Features/events","permalink":"/docs/Features/events","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/openregister/tree/main/website/docs/Features/events.md","tags":[],"version":"current","sidebarPosition":6,"frontMatter":{"title":"Core Features","sidebar_position":6,"description":"An overview of how core concepts in Open Register interact with each other.","keywords":["Open Register","Core Concepts","Relationships"]},"sidebar":"tutorialSidebar","previous":{"title":"Search","permalink":"/docs/Features/search"},"next":{"title":"Files","permalink":"/docs/Features/files"}}');var i=t(4848),r=t(8453);t(5673),t(5537),t(9329);const l={title:"Core Features",sidebar_position:6,description:"An overview of how core concepts in Open Register interact with each other.",keywords:["Open Register","Core Concepts","Relationships"]},d="Events",a={},c=[{value:"What are Events in Open Register?",id:"what-are-events-in-open-register",level:2},{value:"Event Structure",id:"event-structure",level:2},{value:"Event Categories",id:"event-categories",level:2},{value:"1. Schema Events",id:"1-schema-events",level:3},{value:"2. Register Events",id:"2-register-events",level:3},{value:"3. Object Events",id:"3-object-events",level:3},{value:"4. File Events",id:"4-file-events",level:3},{value:"5. Validation Events",id:"5-validation-events",level:3},{value:"Schema Events",id:"schema-events",level:3},{value:"SchemaCreatedEvent",id:"schemacreatedevent",level:4},{value:"SchemaUpdatedEvent",id:"schemaupdatedevent",level:4},{value:"SchemaDeletedEvent",id:"schemadeletedevent",level:4},{value:"Register Events",id:"register-events",level:3},{value:"RegisterCreatedEvent",id:"registercreatedevent",level:4},{value:"RegisterUpdatedEvent",id:"registerupdatedevent",level:4},{value:"RegisterDeletedEvent",id:"registerdeletedevent",level:4},{value:"Object Events",id:"object-events",level:3},{value:"ObjectCreatedEvent",id:"objectcreatedevent",level:4},{value:"ObjectUpdatedEvent",id:"objectupdatedevent",level:4},{value:"ObjectDeletedEvent",id:"objectdeletedevent",level:4},{value:"Example Event",id:"example-event",level:2},{value:"Event-Driven Architecture",id:"event-driven-architecture",level:2},{value:"1. Loose Coupling",id:"1-loose-coupling",level:3},{value:"2. Extensibility",id:"2-extensibility",level:3},{value:"3. Scalability",id:"3-scalability",level:3},{value:"4. Observability",id:"4-observability",level:3},{value:"Working with Events",id:"working-with-events",level:2},{value:"Listening to Events",id:"listening-to-events",level:3},{value:"Registering Event Listeners",id:"registering-event-listeners",level:3},{value:"Event Relationships",id:"event-relationships",level:2},{value:"Events and Objects",id:"events-and-objects",level:3},{value:"Events and Schemas",id:"events-and-schemas",level:3},{value:"Events and Registers",id:"events-and-registers",level:3},{value:"Events and Files",id:"events-and-files",level:3},{value:"Use Cases",id:"use-cases",level:2},{value:"1. Integration",id:"1-integration",level:3},{value:"2. Workflow Automation",id:"2-workflow-automation",level:3},{value:"3. Audit and Compliance",id:"3-audit-and-compliance",level:3},{value:"4. Custom Business Logic",id:"4-custom-business-logic",level:3},{value:"Best Practices",id:"best-practices",level:2},{value:"Conclusion",id:"conclusion",level:2}];function o(e){const n={a:"a",code:"code",h1:"h1",h2:"h2",h3:"h3",h4:"h4",header:"header",li:"li",ol:"ol",p:"p",pre:"pre",strong:"strong",table:"table",tbody:"tbody",td:"td",th:"th",thead:"thead",tr:"tr",ul:"ul",...(0,r.R)(),...e.components};return(0,i.jsxs)(i.Fragment,{children:[(0,i.jsx)(n.header,{children:(0,i.jsx)(n.h1,{id:"events",children:"Events"})}),"\n",(0,i.jsx)(n.h2,{id:"what-are-events-in-open-register",children:"What are Events in Open Register?"}),"\n",(0,i.jsxs)(n.p,{children:["In Open Register, ",(0,i.jsx)(n.strong,{children:"Events"})," are notifications that are triggered when significant actions occur within the system. They form the foundation of Open Register's event-driven architecture, enabling loose coupling between components while facilitating rich integration possibilities."]}),"\n",(0,i.jsx)(n.p,{children:"Events in Open Register are:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Triggered automatically at key points in the application lifecycle"}),"\n",(0,i.jsx)(n.li,{children:"Standardized messages containing relevant data about what occurred"}),"\n",(0,i.jsx)(n.li,{children:"Available for other components to listen and respond to"}),"\n",(0,i.jsx)(n.li,{children:"Essential for building extensible, integrated systems"}),"\n",(0,i.jsx)(n.li,{children:"Compatible with Nextcloud's event dispatcher system"}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"event-structure",children:"Event Structure"}),"\n",(0,i.jsx)(n.p,{children:"An event in Open Register consists of the following key components:"}),"\n",(0,i.jsxs)(n.table,{children:[(0,i.jsx)(n.thead,{children:(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.th,{children:"Component"}),(0,i.jsx)(n.th,{children:"Description"})]})}),(0,i.jsxs)(n.tbody,{children:[(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:"Event Class"}),(0,i.jsx)(n.td,{children:"The PHP class that defines the event type"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:"Event Data"}),(0,i.jsx)(n.td,{children:"The data payload carried by the event"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:"Timestamp"}),(0,i.jsx)(n.td,{children:"When the event occurred"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:"Source"}),(0,i.jsx)(n.td,{children:"The component that triggered the event"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:"Context"}),(0,i.jsx)(n.td,{children:"Additional contextual information"})]})]})]}),"\n",(0,i.jsx)(n.h2,{id:"event-categories",children:"Event Categories"}),"\n",(0,i.jsx)(n.p,{children:"Open Register provides several categories of events:"}),"\n",(0,i.jsx)(n.h3,{id:"1-schema-events",children:"1. Schema Events"}),"\n",(0,i.jsx)(n.p,{children:"Events related to schema lifecycle:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"SchemaCreatedEvent"}),": Triggered when a new schema is created"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"SchemaUpdatedEvent"}),": Triggered when a schema is updated"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"SchemaDeletedEvent"}),": Triggered when a schema is deleted"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"2-register-events",children:"2. Register Events"}),"\n",(0,i.jsx)(n.p,{children:"Events related to register lifecycle:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"RegisterCreatedEvent"}),": Triggered when a new register is created"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"RegisterUpdatedEvent"}),": Triggered when a register is updated"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"RegisterDeletedEvent"}),": Triggered when a register is deleted"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"3-object-events",children:"3. Object Events"}),"\n",(0,i.jsx)(n.p,{children:"Events related to object lifecycle:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"ObjectCreatedEvent"}),": Triggered when a new object is created"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"ObjectUpdatedEvent"}),": Triggered when an object is updated"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"ObjectDeletedEvent"}),": Triggered when an object is deleted"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"4-file-events",children:"4. File Events"}),"\n",(0,i.jsx)(n.p,{children:"Events related to file operations:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"FileUploadedEvent"}),": Triggered when a file is uploaded"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"FileUpdatedEvent"}),": Triggered when a file is updated"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"FileDeletedEvent"}),": Triggered when a file is deleted"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"5-validation-events",children:"5. Validation Events"}),"\n",(0,i.jsx)(n.p,{children:"Events related to validation:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"ValidationSucceededEvent"}),": Triggered when validation succeeds"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"ValidationFailedEvent"}),": Triggered when validation fails"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"schema-events",children:"Schema Events"}),"\n",(0,i.jsx)(n.h4,{id:"schemacreatedevent",children:"SchemaCreatedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\SchemaCreatedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When a new schema is created in the system"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getSchema()"}),": Returns the Schema object that was created"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Can be used to perform additional setup or trigger notifications when new schemas are created"]}),"\n"]}),"\n",(0,i.jsx)(n.h4,{id:"schemaupdatedevent",children:"SchemaUpdatedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\SchemaUpdatedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When a schema is updated"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getSchema()"}),": Returns the updated Schema object"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getOldSchema()"}),": Returns the Schema object before updates"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Useful for tracking changes to schemas and triggering related actions"]}),"\n"]}),"\n",(0,i.jsx)(n.h4,{id:"schemadeletedevent",children:"SchemaDeletedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\SchemaDeletedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When a schema is deleted from the system"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getSchema()"}),": Returns the Schema object that was deleted"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Can be used to perform cleanup or trigger additional actions when schemas are removed"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"register-events",children:"Register Events"}),"\n",(0,i.jsx)(n.h4,{id:"registercreatedevent",children:"RegisterCreatedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\RegisterCreatedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When a new register is created"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getRegister()"}),": Returns the Register object that was created"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Can be used to perform additional setup or trigger notifications when new registers are created"]}),"\n"]}),"\n",(0,i.jsx)(n.h4,{id:"registerupdatedevent",children:"RegisterUpdatedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\RegisterUpdatedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When a register is updated"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getRegister()"}),": Returns the updated Register object"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getOldRegister()"}),": Returns the Register object before updates"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Useful for tracking changes to registers and triggering related actions"]}),"\n"]}),"\n",(0,i.jsx)(n.h4,{id:"registerdeletedevent",children:"RegisterDeletedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\RegisterDeletedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When a register is deleted"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getRegister()"}),": Returns the Register object that was deleted"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Can be used for cleanup operations or notifications when registers are removed"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"object-events",children:"Object Events"}),"\n",(0,i.jsx)(n.h4,{id:"objectcreatedevent",children:"ObjectCreatedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\ObjectCreatedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When a new object is created in a register"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getObject()"}),": Returns the ObjectEntity that was created"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Useful for tracking new entries, triggering notifications, or performing additional processing on new objects"]}),"\n"]}),"\n",(0,i.jsx)(n.h4,{id:"objectupdatedevent",children:"ObjectUpdatedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\ObjectUpdatedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When an existing object is updated in a register"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getObject()"}),": Returns the updated ObjectEntity"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getOldObject()"}),": Returns the ObjectEntity before updates"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Useful for tracking changes to objects, auditing modifications, or triggering follow-up actions"]}),"\n"]}),"\n",(0,i.jsx)(n.h4,{id:"objectdeletedevent",children:"ObjectDeletedEvent"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Class"}),": ",(0,i.jsx)(n.code,{children:"OCA\\OpenRegister\\Event\\ObjectDeletedEvent"})]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Triggered"}),": When an object is deleted from a register"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Data Provided"}),":","\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.code,{children:"getObject()"}),": Returns the ObjectEntity that was deleted"]}),"\n"]}),"\n"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Usage"}),": Can be used for cleanup operations, maintaining related data integrity, or sending notifications about deletions"]}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"example-event",children:"Example Event"}),"\n",(0,i.jsxs)(n.p,{children:["Here's an example of an ",(0,i.jsx)(n.code,{children:"ObjectCreatedEvent"}),":"]}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"namespace OCA\\OpenRegister\\Event;\n\nuse OCA\\OpenRegister\\Entity\\ObjectEntity;\nuse OCP\\EventDispatcher\\Event;\n\nclass ObjectCreatedEvent extends Event {\n    private ObjectEntity $object;\n\n    public function __construct(ObjectEntity $object) {\n        parent::__construct();\n        $this->object = $object;\n    }\n\n    public function getObject(): ObjectEntity {\n        return $this->object;\n    }\n}\n"})}),"\n",(0,i.jsx)(n.h2,{id:"event-driven-architecture",children:"Event-Driven Architecture"}),"\n",(0,i.jsx)(n.p,{children:"Open Register uses an event-driven architecture to provide several benefits:"}),"\n",(0,i.jsx)(n.h3,{id:"1-loose-coupling",children:"1. Loose Coupling"}),"\n",(0,i.jsx)(n.p,{children:"Components can interact without direct dependencies:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"The event publisher doesn't need to know who is listening"}),"\n",(0,i.jsx)(n.li,{children:"Listeners can be added or removed without changing the publisher"}),"\n",(0,i.jsx)(n.li,{children:"Different parts of the system can evolve independently"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"2-extensibility",children:"2. Extensibility"}),"\n",(0,i.jsx)(n.p,{children:"The event system makes Open Register highly extensible:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"New functionality can be added by listening to existing events"}),"\n",(0,i.jsx)(n.li,{children:"Third-party applications can integrate without modifying core code"}),"\n",(0,i.jsx)(n.li,{children:"Custom business logic can be implemented through event listeners"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"3-scalability",children:"3. Scalability"}),"\n",(0,i.jsx)(n.p,{children:"Event-driven architectures support better scalability:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Processing can be distributed across different components"}),"\n",(0,i.jsx)(n.li,{children:"Asynchronous handling allows for better resource management"}),"\n",(0,i.jsx)(n.li,{children:"Event queues can buffer processing during peak loads"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"4-observability",children:"4. Observability"}),"\n",(0,i.jsx)(n.p,{children:"Events provide better system observability:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"System activities can be monitored through events"}),"\n",(0,i.jsx)(n.li,{children:"Audit trails can be built by capturing events"}),"\n",(0,i.jsx)(n.li,{children:"Debugging is easier with a clear event timeline"}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"working-with-events",children:"Working with Events"}),"\n",(0,i.jsx)(n.h3,{id:"listening-to-events",children:"Listening to Events"}),"\n",(0,i.jsx)(n.p,{children:"To listen to events in Open Register, you need to:"}),"\n",(0,i.jsxs)(n.ol,{children:["\n",(0,i.jsx)(n.li,{children:"Create an event listener class"}),"\n",(0,i.jsx)(n.li,{children:"Register it with Nextcloud's event dispatcher"}),"\n"]}),"\n",(0,i.jsxs)(n.p,{children:["Here's an example of a listener for ",(0,i.jsx)(n.code,{children:"ObjectCreatedEvent"}),":"]}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"namespace OCA\\MyApp\\Listener;\n\nuse OCA\\OpenRegister\\Event\\ObjectCreatedEvent;\nuse OCP\\EventDispatcher\\Event;\nuse OCP\\EventDispatcher\\IEventListener;\n\nclass ObjectCreatedListener implements IEventListener {\n    public function handle(Event $event): void {\n        if (!($event instanceof ObjectCreatedEvent)) {\n            return;\n        }\n        \n        $object = $event->getObject();\n        // Perform actions with the new object\n    }\n}\n"})}),"\n",(0,i.jsx)(n.h3,{id:"registering-event-listeners",children:"Registering Event Listeners"}),"\n",(0,i.jsxs)(n.p,{children:["Register your listener in your app's ",(0,i.jsx)(n.code,{children:"Application.php"})," file:"]}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"use OCA\\OpenRegister\\Event\\ObjectCreatedEvent;\nuse OCA\\MyApp\\Listener\\ObjectCreatedListener;\nuse OCP\\EventDispatcher\\IEventDispatcher;\n\n// In the register() method:\n$dispatcher = $this->getContainer()->get(IEventDispatcher::class);\n$dispatcher->addServiceListener(ObjectCreatedEvent::class, ObjectCreatedListener::class);\n"})}),"\n",(0,i.jsxs)(n.p,{children:["If you're extending Open Register, you might need to dispatch your own events. You can read more about event handling in the ",(0,i.jsx)(n.a,{href:"https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html",children:"Nextcloud documentation"}),"."]}),"\n",(0,i.jsx)(n.h2,{id:"event-relationships",children:"Event Relationships"}),"\n",(0,i.jsx)(n.p,{children:"Events have important relationships with other core concepts:"}),"\n",(0,i.jsx)(n.h3,{id:"events-and-objects",children:"Events and Objects"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Events are triggered by changes to objects"}),"\n",(0,i.jsx)(n.li,{children:"Events carry object data"}),"\n",(0,i.jsx)(n.li,{children:"Events enable tracking object lifecycle"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"events-and-schemas",children:"Events and Schemas"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Schema changes trigger events"}),"\n",(0,i.jsx)(n.li,{children:"Events can be used to validate schema compatibility"}),"\n",(0,i.jsx)(n.li,{children:"Events enable schema evolution tracking"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"events-and-registers",children:"Events and Registers"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Register operations trigger events"}),"\n",(0,i.jsx)(n.li,{children:"Events can be used to monitor register usage"}),"\n",(0,i.jsx)(n.li,{children:"Events enable register lifecycle management"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"events-and-files",children:"Events and Files"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"File operations trigger events"}),"\n",(0,i.jsx)(n.li,{children:"Events carry file metadata"}),"\n",(0,i.jsx)(n.li,{children:"Events enable file processing workflows"}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"use-cases",children:"Use Cases"}),"\n",(0,i.jsx)(n.h3,{id:"1-integration",children:"1. Integration"}),"\n",(0,i.jsx)(n.p,{children:"Use events to integrate with other systems:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Sync data with external systems"}),"\n",(0,i.jsx)(n.li,{children:"Trigger notifications in messaging platforms"}),"\n",(0,i.jsx)(n.li,{children:"Update search indexes"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"2-workflow-automation",children:"2. Workflow Automation"}),"\n",(0,i.jsx)(n.p,{children:"Build automated workflows:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Generate documents when objects are created"}),"\n",(0,i.jsx)(n.li,{children:"Send approval requests when objects are updated"}),"\n",(0,i.jsx)(n.li,{children:"Archive data when objects are deleted"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"3-audit-and-compliance",children:"3. Audit and Compliance"}),"\n",(0,i.jsx)(n.p,{children:"Implement audit and compliance features:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Log all changes to sensitive data"}),"\n",(0,i.jsx)(n.li,{children:"Track who did what and when"}),"\n",(0,i.jsx)(n.li,{children:"Generate compliance reports"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"4-custom-business-logic",children:"4. Custom Business Logic"}),"\n",(0,i.jsx)(n.p,{children:"Implement custom business logic:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Validate complex business rules"}),"\n",(0,i.jsx)(n.li,{children:"Enforce data quality standards"}),"\n",(0,i.jsx)(n.li,{children:"Implement approval workflows"}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"best-practices",children:"Best Practices"}),"\n",(0,i.jsxs)(n.ol,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Keep Listeners Focused"}),": Each listener should have a single responsibility"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Handle Errors Gracefully"}),": Listeners should not break the system if they fail"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Consider Performance"}),": Heavy processing should be done asynchronously"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Document Events"}),": Clearly document what events are available and when they're triggered"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Version Events"}),": Consider versioning events to handle changes over time"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Test Event Handling"}),": Write tests for event listeners"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Monitor Event Flow"}),": Implement monitoring for event processing"]}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"conclusion",children:"Conclusion"}),"\n",(0,i.jsx)(n.p,{children:"Events in Open Register provide a powerful mechanism for extending functionality, integrating with other systems, and building loosely coupled architectures. By leveraging the event-driven approach, you can create flexible, scalable applications that can evolve over time while maintaining a clean separation of concerns."})]})}function h(e={}){const{wrapper:n}={...(0,r.R)(),...e.components};return n?(0,i.jsx)(n,{...e,children:(0,i.jsx)(o,{...e})}):o(e)}},3290:()=>{},5537:(e,n,t)=>{"use strict";t.d(n,{A:()=>w});var s=t(6540),i=t(8215),r=t(5627),l=t(6347),d=t(372),a=t(604),c=t(1861),o=t(8749);function h(e){return s.Children.toArray(e).filter((e=>"\n"!==e)).map((e=>{if(!e||(0,s.isValidElement)(e)&&function(e){const{props:n}=e;return!!n&&"object"==typeof n&&"value"in n}(e))return e;throw new Error(`Docusaurus error: Bad <Tabs> child <${"string"==typeof e.type?e.type:e.type.name}>: all children of the <Tabs> component should be <TabItem>, and every <TabItem> should have a unique "value" prop.`)}))?.filter(Boolean)??[]}function u(e){const{values:n,children:t}=e;return(0,s.useMemo)((()=>{const e=n??function(e){return h(e).map((e=>{let{props:{value:n,label:t,attributes:s,default:i}}=e;return{value:n,label:t,attributes:s,default:i}}))}(t);return function(e){const n=(0,c.XI)(e,((e,n)=>e.value===n.value));if(n.length>0)throw new Error(`Docusaurus error: Duplicate values "${n.map((e=>e.value)).join(", ")}" found in <Tabs>. Every value needs to be unique.`)}(e),e}),[n,t])}function v(e){let{value:n,tabValues:t}=e;return t.some((e=>e.value===n))}function j(e){let{queryString:n=!1,groupId:t}=e;const i=(0,l.W6)(),r=function(e){let{queryString:n=!1,groupId:t}=e;if("string"==typeof n)return n;if(!1===n)return null;if(!0===n&&!t)throw new Error('Docusaurus error: The <Tabs> component groupId prop is required if queryString=true, because this value is used as the search param name. You can also provide an explicit value such as queryString="my-search-param".');return t??null}({queryString:n,groupId:t});return[(0,a.aZ)(r),(0,s.useCallback)((e=>{if(!r)return;const n=new URLSearchParams(i.location.search);n.set(r,e),i.replace({...i.location,search:n.toString()})}),[r,i])]}function g(e){const{defaultValue:n,queryString:t=!1,groupId:i}=e,r=u(e),[l,a]=(0,s.useState)((()=>function(e){let{defaultValue:n,tabValues:t}=e;if(0===t.length)throw new Error("Docusaurus error: the <Tabs> component requires at least one <TabItem> children component");if(n){if(!v({value:n,tabValues:t}))throw new Error(`Docusaurus error: The <Tabs> has a defaultValue "${n}" but none of its children has the corresponding value. Available values are: ${t.map((e=>e.value)).join(", ")}. If you intend to show no default tab, use defaultValue={null} instead.`);return n}const s=t.find((e=>e.default))??t[0];if(!s)throw new Error("Unexpected error: 0 tabValues");return s.value}({defaultValue:n,tabValues:r}))),[c,h]=j({queryString:t,groupId:i}),[g,x]=function(e){let{groupId:n}=e;const t=function(e){return e?`docusaurus.tab.${e}`:null}(n),[i,r]=(0,o.Dv)(t);return[i,(0,s.useCallback)((e=>{t&&r.set(e)}),[t,r])]}({groupId:i}),p=(()=>{const e=c??g;return v({value:e,tabValues:r})?e:null})();(0,d.A)((()=>{p&&a(p)}),[p]);return{selectedValue:l,selectValue:(0,s.useCallback)((e=>{if(!v({value:e,tabValues:r}))throw new Error(`Can't select invalid tab value=${e}`);a(e),h(e),x(e)}),[h,x,r]),tabValues:r}}var x=t(9136);const p={tabList:"tabList__CuJ",tabItem:"tabItem_LNqP"};var b=t(4848);function m(e){let{className:n,block:t,selectedValue:s,selectValue:l,tabValues:d}=e;const a=[],{blockElementScrollPositionUntilNextRender:c}=(0,r.a_)(),o=e=>{const n=e.currentTarget,t=a.indexOf(n),i=d[t].value;i!==s&&(c(n),l(i))},h=e=>{let n=null;switch(e.key){case"Enter":o(e);break;case"ArrowRight":{const t=a.indexOf(e.currentTarget)+1;n=a[t]??a[0];break}case"ArrowLeft":{const t=a.indexOf(e.currentTarget)-1;n=a[t]??a[a.length-1];break}}n?.focus()};return(0,b.jsx)("ul",{role:"tablist","aria-orientation":"horizontal",className:(0,i.A)("tabs",{"tabs--block":t},n),children:d.map((e=>{let{value:n,label:t,attributes:r}=e;return(0,b.jsx)("li",{role:"tab",tabIndex:s===n?0:-1,"aria-selected":s===n,ref:e=>{a.push(e)},onKeyDown:h,onClick:o,...r,className:(0,i.A)("tabs__item",p.tabItem,r?.className,{"tabs__item--active":s===n}),children:t??n},n)}))})}function f(e){let{lazy:n,children:t,selectedValue:r}=e;const l=(Array.isArray(t)?t:[t]).filter(Boolean);if(n){const e=l.find((e=>e.props.value===r));return e?(0,s.cloneElement)(e,{className:(0,i.A)("margin-top--md",e.props.className)}):null}return(0,b.jsx)("div",{className:"margin-top--md",children:l.map(((e,n)=>(0,s.cloneElement)(e,{key:n,hidden:e.props.value!==r})))})}function E(e){const n=g(e);return(0,b.jsxs)("div",{className:(0,i.A)("tabs-container",p.tabList),children:[(0,b.jsx)(m,{...n,...e}),(0,b.jsx)(f,{...n,...e})]})}function w(e){const n=(0,x.A)();return(0,b.jsx)(E,{...e,children:h(e.children)},String(n))}},5673:(e,n,t)=>{"use strict";t.d(n,{A:()=>u});var s=t(6540),i=t(53),r=t(4404),l=(t(4345),t(8794)),d=t(4022),a=t(2077);function c(e){const n=(0,a.kh)("docusaurus-plugin-redoc");return e?n?.[e]:Object.values(n??{})?.[0]}var o=t(4848);const h=e=>{let{id:n,example:t,pointer:a,...h}=e;const u=c(n),{store:v}=(0,d.r)(u);return(0,s.useEffect)((()=>{v.menu.dispose()}),[v]),(0,o.jsx)(r.ThemeProvider,{theme:v.options.theme,children:(0,o.jsx)("div",{className:(0,i.A)(["redocusaurus","redocusaurus-schema",t?null:"hide-example"]),children:(0,o.jsx)(l.SchemaDefinition,{parser:v.spec.parser,options:v.options,schemaRef:a,...h})})})};h.defaultProps={example:!1};const u=h},7411:()=>{},7992:()=>{},8453:(e,n,t)=>{"use strict";t.d(n,{R:()=>l,x:()=>d});var s=t(6540);const i={},r=s.createContext(i);function l(e){const n=s.useContext(r);return s.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function d(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(i):e.components||i:l(e.components),s.createElement(r.Provider,{value:n},e.children)}},8825:()=>{},9329:(e,n,t)=>{"use strict";t.d(n,{A:()=>l});t(6540);var s=t(8215);const i={tabItem:"tabItem_Ymn6"};var r=t(4848);function l(e){let{children:n,hidden:t,className:l}=e;return(0,r.jsx)("div",{role:"tabpanel",className:(0,s.A)(i.tabItem,l),hidden:t,children:n})}}}]);