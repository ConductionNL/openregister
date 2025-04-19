"use strict";(self.webpackChunkopen_catalogi_docs=self.webpackChunkopen_catalogi_docs||[]).push([[637],{2068:(e,n,t)=>{t.r(n),t.d(n,{assets:()=>r,contentTitle:()=>l,default:()=>p,frontMatter:()=>o,metadata:()=>s,toc:()=>c});const s=JSON.parse('{"id":"developers/object-handling","title":"Object Handling","description":"The OpenRegister application uses a sophisticated object handling system that provides a fluent interface for working with objects. This system is built around the ObjectService class and various response classes that enable method chaining, pagination, and data export.","source":"@site/docs/developers/object-handling.md","sourceDirName":"developers","slug":"/developers/object-handling","permalink":"/docs/developers/object-handling","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/openregister/tree/main/website/docs/developers/object-handling.md","tags":[],"version":"current","frontMatter":{},"sidebar":"tutorialSidebar","previous":{"title":"Object Handlers","permalink":"/docs/developers/object-handlers"},"next":{"title":"Response Classes","permalink":"/docs/developers/response-classes"}}');var i=t(4848),a=t(8453);const o={},l="Object Handling",r={},c=[{value:"Basic Usage",id:"basic-usage",level:2},{value:"Method Chaining",id:"method-chaining",level:2},{value:"Pagination",id:"pagination",level:2},{value:"Data Export",id:"data-export",level:2},{value:"Response Types",id:"response-types",level:2},{value:"ObjectResponse",id:"objectresponse",level:3},{value:"SingleObjectResponse",id:"singleobjectresponse",level:3},{value:"MultipleObjectResponse",id:"multipleobjectresponse",level:3},{value:"Working with Relations",id:"working-with-relations",level:2},{value:"Working with Logs",id:"working-with-logs",level:2},{value:"Context Management",id:"context-management",level:2},{value:"Error Handling",id:"error-handling",level:2},{value:"Best Practices",id:"best-practices",level:2},{value:"Technical Details",id:"technical-details",level:2},{value:"Supported Download Formats",id:"supported-download-formats",level:3},{value:"Pagination Parameters",id:"pagination-parameters",level:3},{value:"Response Data Structure",id:"response-data-structure",level:3}];function d(e){const n={code:"code",h1:"h1",h2:"h2",h3:"h3",header:"header",li:"li",ol:"ol",p:"p",pre:"pre",ul:"ul",...(0,a.R)(),...e.components};return(0,i.jsxs)(i.Fragment,{children:[(0,i.jsx)(n.header,{children:(0,i.jsx)(n.h1,{id:"object-handling",children:"Object Handling"})}),"\n",(0,i.jsx)(n.p,{children:"The OpenRegister application uses a sophisticated object handling system that provides a fluent interface for working with objects. This system is built around the ObjectService class and various response classes that enable method chaining, pagination, and data export."}),"\n",(0,i.jsx)(n.h2,{id:"basic-usage",children:"Basic Usage"}),"\n",(0,i.jsx)(n.p,{children:"The ObjectService provides a fluent interface for working with objects. Here are some basic examples:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"// Get a single object\n$object = $objectService\n    ->setRegister($register)\n    ->setSchema($schema)\n    ->getObject($uuid);\n\n// Get multiple objects\n$objects = $objectService\n    ->setRegister($register)\n    ->setSchema($schema)\n    ->getObjects(['status' => 'active']);\n"})}),"\n",(0,i.jsx)(n.h2,{id:"method-chaining",children:"Method Chaining"}),"\n",(0,i.jsx)(n.p,{children:"The system supports method chaining for various operations:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"// Get an object with its relations\n$objectService->getObject($uuid)->getRelations()->paginate();\n\n// Get an object's logs\n$objectService->getObject($uuid)->getLogs()->paginate();\n\n// Get multiple objects and paginate\n$objectService->getObjects()->paginate(1, 10);\n"})}),"\n",(0,i.jsx)(n.h2,{id:"pagination",children:"Pagination"}),"\n",(0,i.jsx)(n.p,{children:"All responses support pagination through the paginate() method:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"$response = $objectService->getObjects()\n    ->paginate(\n        page: 1,      // The page number\n        limit: 10,    // Items per page\n        total: 100    // Total number of items\n    );\n\n// The paginated response includes metadata\n$result = $response->getData();\n// {\n//     'data': [...],\n//     'pagination': {\n//         'page': 1,\n//         'limit': 10,\n//         'total': 100,\n//         'pages': 10\n//     }\n// }\n"})}),"\n",(0,i.jsx)(n.h2,{id:"data-export",children:"Data Export"}),"\n",(0,i.jsx)(n.p,{children:"Objects can be exported in various formats using the download() method:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"// Download as JSON\n$jsonData = $objectService->getObjects()->download('json');\n\n// Download as XML\n$xmlData = $objectService->getObjects()->download('xml');\n\n// Download as CSV\n$csvData = $objectService->getObjects()->download('csv');\n\n// Download as Excel\n$excelData = $objectService->getObjects()->download('excel');\n"})}),"\n",(0,i.jsx)(n.h2,{id:"response-types",children:"Response Types"}),"\n",(0,i.jsx)(n.p,{children:"The system includes three types of responses:"}),"\n",(0,i.jsx)(n.h3,{id:"objectresponse",children:"ObjectResponse"}),"\n",(0,i.jsx)(n.p,{children:"Base response class that provides:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Pagination functionality"}),"\n",(0,i.jsx)(n.li,{children:"Download capabilities"}),"\n",(0,i.jsx)(n.li,{children:"Data formatting"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"singleobjectresponse",children:"SingleObjectResponse"}),"\n",(0,i.jsx)(n.p,{children:"Response for single object operations:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Extends ObjectResponse"}),"\n",(0,i.jsx)(n.li,{children:"Provides access to relations"}),"\n",(0,i.jsx)(n.li,{children:"Includes log retrieval"}),"\n",(0,i.jsx)(n.li,{children:"Maintains object state"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"multipleobjectresponse",children:"MultipleObjectResponse"}),"\n",(0,i.jsx)(n.p,{children:"Response for multiple object operations:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"Extends ObjectResponse"}),"\n",(0,i.jsx)(n.li,{children:"Handles bulk relations"}),"\n",(0,i.jsx)(n.li,{children:"Supports bulk log retrieval"}),"\n",(0,i.jsx)(n.li,{children:"Manages collections of objects"}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"working-with-relations",children:"Working with Relations"}),"\n",(0,i.jsx)(n.p,{children:"Relations can be retrieved and paginated:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"// Get relations for a single object\n$relations = $objectService\n    ->getObject($uuid)\n    ->getRelations()\n    ->paginate();\n\n// Get relations for multiple objects\n$relations = $objectService\n    ->getObjects()\n    ->getRelations()\n    ->paginate();\n"})}),"\n",(0,i.jsx)(n.h2,{id:"working-with-logs",children:"Working with Logs"}),"\n",(0,i.jsx)(n.p,{children:"Object logs can be retrieved and paginated:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"// Get logs for a single object\n$logs = $objectService\n    ->getObject($uuid)\n    ->getLogs()\n    ->paginate();\n\n// Get logs directly\n$logs = $objectService\n    ->getLogs($uuid)\n    ->paginate();\n\n// Get logs for multiple objects\n$logs = $objectService\n    ->getObjects()\n    ->getLogs()\n    ->paginate();\n"})}),"\n",(0,i.jsx)(n.h2,{id:"context-management",children:"Context Management"}),"\n",(0,i.jsx)(n.p,{children:"The ObjectService maintains register and schema context:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"$objectService\n    ->setRegister($register)\n    ->setSchema($schema)\n    ->getObject($uuid);\n"})}),"\n",(0,i.jsx)(n.h2,{id:"error-handling",children:"Error Handling"}),"\n",(0,i.jsx)(n.p,{children:"The system includes proper error handling:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"try {\n    $object = $objectService->getObject($uuid);\n} catch (DoesNotExistException $e) {\n    // Handle not found error\n} catch (Exception $e) {\n    // Handle other errors\n}\n"})}),"\n",(0,i.jsx)(n.h2,{id:"best-practices",children:"Best Practices"}),"\n",(0,i.jsxs)(n.ol,{children:["\n",(0,i.jsx)(n.li,{children:"Always set register and schema context before performing operations"}),"\n",(0,i.jsx)(n.li,{children:"Use pagination for large datasets"}),"\n",(0,i.jsx)(n.li,{children:"Chain methods appropriately for the desired outcome"}),"\n",(0,i.jsx)(n.li,{children:"Handle errors appropriately"}),"\n",(0,i.jsx)(n.li,{children:"Use the most specific response type for your needs"}),"\n",(0,i.jsx)(n.li,{children:"Consider memory usage when working with large datasets"}),"\n",(0,i.jsx)(n.li,{children:"Use appropriate download formats for different use cases"}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"technical-details",children:"Technical Details"}),"\n",(0,i.jsx)(n.h3,{id:"supported-download-formats",children:"Supported Download Formats"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"JSON: Using Symfony Serializer with JsonEncoder"}),"\n",(0,i.jsx)(n.li,{children:"XML: Using Symfony Serializer with XmlEncoder"}),"\n",(0,i.jsx)(n.li,{children:"CSV: Using Symfony Serializer with CsvEncoder"}),"\n",(0,i.jsx)(n.li,{children:"Excel: Using PhpSpreadsheet"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"pagination-parameters",children:"Pagination Parameters"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"page: The page number (default: 1)"}),"\n",(0,i.jsx)(n.li,{children:"limit: Items per page (default: 10)"}),"\n",(0,i.jsx)(n.li,{children:"total: Total number of items (optional)"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"response-data-structure",children:"Response Data Structure"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-php",children:"[\n    'data' => [...],           // The actual data\n    'pagination' => [          // Only present when paginated\n        'page' => int,         // Current page\n        'limit' => int,        // Items per page\n        'total' => int,        // Total items\n        'pages' => int,        // Total pages\n    ]\n]\n"})})]})}function p(e={}){const{wrapper:n}={...(0,a.R)(),...e.components};return n?(0,i.jsx)(n,{...e,children:(0,i.jsx)(d,{...e})}):d(e)}},8453:(e,n,t)=>{t.d(n,{R:()=>o,x:()=>l});var s=t(6540);const i={},a=s.createContext(i);function o(e){const n=s.useContext(a);return s.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function l(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(i):e.components||i:o(e.components),s.createElement(a.Provider,{value:n},e.children)}}}]);