# Coverage Report — openregister

- Generated: 2026-05-24T09:04:37.719673+00:00
- Branch: `chore/spec-collect-2026-05-24`
- Scanner: opsx-coverage-scan v1-scripted

## Inventory

- Spec files scanned: **198** (122 live REQs, 76 archived)
- PHP files: **596** (4748 methods) — skipped `lib/Migration/`, `lib/Db/`
- Vue/JS/TS files: **296** (346 methods) — skipped tests + `main.js`/`bootstrap.js`

## Summary

| Bucket | Count | Next action |
|---|---:|---|
| annotated (already carries `@spec`) | 1744 | — |
| plumbing | 3 | — |
| **1** — REQ matched (high conf ≥0.85) | **112** | `/opsx-annotate openregister` (verify samples first) |
| **1** — REQ matched (NEEDS-REVIEW 0.70–0.85) | **56** | Human triage before annotating |
| **2a** — known capability, no REQ | **1321** (30 clusters) | `/opsx-reverse-spec openregister --extend <cap>` |
| **2b** — no capability owner | **1858** (22 clusters) | `/opsx-reverse-spec openregister --cluster <name>` |
| **3a** — possibly broken (code removed in git history) | **17** | Separate fix PR |
| **3b** — REQ never implemented | **0** | Defer or remove |
| **4** — ADR conformance | **649** findings, 3 rules | Follow-up issues |

Pass B private-helper promotions: **211** (private/protected methods inherited a REQ from a public caller in the same file)

## How to read this report

The scanner is a **deterministic Python scorer** that extracts signals per SKILL.md Pass A guidance: file path tokens, class+method name tokens, first docblock line, REQ title + scenario keywords. It does NOT read full method bodies — judgments are based on signature-level signals only. **Every Bucket 1 entry should be human-verified before annotation.** The `signal:` column documents what triggered each match so you can audit fast.

**Common false-positive patterns to watch for:**

- Generic file names (Setting, Controller, Job) matching multiple REQs that share those generic tokens — discount the path signal heavily
- Utility methods (slugify, toArray, jsonSerialize) bucketed into the first REQ that shares any token with the file path — these are usually plumbing
- Constructors that ended up in Bucket 1 — typically inherit from class-level signal and should be plumbing instead

## Bucket 1 — Ready to annotate (verify before running `/opsx-annotate`)

### High confidence (≥0.85)

#### actions (7 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Service/ActionExecutor.php | `executeSingleAction()` | REQ-003 | 0.85 | name:action,single | doc:action,single | path:action,executor |
| lib/Service/ActionExecutor.php | `processWorkflowResult()` | REQ-003 | 0.95 | name:execution,result,sync,workflow | doc:execution,result,sync,workflow | path: |
| lib/Service/ActionExecutor.php | `handleFailure()` | REQ-004 | 0.95 | name:action,execution,failure,mode | doc:action,execution,failure,mode | path:ac |
| lib/Service/ActionExecutor.php | `createLogEntry()` | REQ-003 | 0.95 | name:action,entry,execution | doc:action,entry,execution | path:action,executor, |
| lib/Service/ConditionMatcher.php | `filterOrganisationMatchForCreate()` | REQ-002 | 0.85 | name:filter,matching | doc:filter,matching | path:condition,php |
| lib/Service/ConditionMatcher.php | `singleConditionMatches()` | REQ-002 | 0.85 | name:condition,if | doc:condition,if | path:condition,php |
| lib/Service/ConditionMatcher.php | `getActiveOrganisationUuid()` | REQ-002 | 0.85 | name:active,uuid | doc:active,uuid | path:condition,php |

#### archival-annotation-vocabulary (1 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Service/Archival/ArchivalAnnotationValidator.php | `isIsoDuration()` | ISO-8601 | 1.0 | name:8601,iso,string | doc:8601,iso,string | path:annotation,archival | cap-alig |

#### archival-destruction-workflow (6 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Service/Archival/ArchiefactiedatumCalculator.php | `brondatumFromProperty()` | REQ-007 | 1.0 | name:eigenschap,method,property | doc:eigenschap,method,property | path:archiefa |
| lib/Service/ArchivalService.php | `destroyObject()` | REQ-003 | 0.95 | name:audit,single,trail | doc:audit,single,trail | path:archival | cap-aligned:a |
| lib/Service/RetentionService.php | `placeLegalHold()` | REQ-006 | 0.85 | name:hold,legal,place | doc:hold,legal,place | path:retention |
| lib/Service/RetentionService.php | `releaseLegalHold()` | REQ-006 | 0.85 | name:hold,legal,release | doc:hold,legal,release | path:retention |
| lib/Service/RetentionService.php | `hasActiveLegalHold()` | REQ-006 | 0.85 | name:active,check,hold,legal | doc:active,check,hold,legal | path:retention |
| lib/Service/RetentionService.php | `extendArchiefactiedatum()` | REQ-003 | 0.85 | name:archiefactiedatum,excluded,period | doc:archiefactiedatum,excluded,period | |

#### chat-ai (32 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Controller/ChatController.php | `extractMessageRequestParams()` | REQ-001 | 0.85 | name:message,request | doc:message,request | path:chat | cap-aligned:chat |
| lib/Controller/ChatController.php | `loadExistingConversation()` | REQ-001 | 0.95 | name:conversation,existing,uuid | doc:conversation,existing,uuid | path:chat | c |
| lib/Controller/ChatController.php | `createNewConversation()` | REQ-001 | 0.85 | name:agent,conversation | doc:agent,conversation | path:chat | cap-aligned:chat |
| lib/Controller/ChatController.php | `resolveConversation()` | REQ-001 | 0.95 | name:agent,conversation,one,resolve,uuid | doc:agent,conversation,one,resolve,uu |
| lib/Controller/ChatController.php | `verifyConversationAccess()` | REQ-001 | 0.95 | name:conversation,has,verify | doc:conversation,has,verify | path:chat | cap-ali |
| lib/Controller/ChatStreamController.php | `resolveConversation()` | REQ-001 | 0.95 | name:conversation,request,resolve | doc:conversation,request,resolve | path:chat |
| lib/Service/Chat/ContextRetrievalHandler.php | `__construct()` | REQ-001 | 0.95 | name:chat,handler | doc:chat,handler | path:chat,handler | cap-aligned:chat |
| lib/Service/Chat/ContextRetrievalHandler.php | `retrieveContext()` | REQ-001 | 1.0 | name:chat,rag,retrieve,search,using | doc:chat,rag,retrieve,search,using | path: |
| lib/Service/Chat/ContextRetrievalHandler.php | `searchKeywordOnly()` | REQ-001 | 1.0 | name:only,search,using | doc:only,search,using | path:chat,handler | cap-aligned |
| lib/Service/Chat/ContextRetrievalHandler.php | `extractSourceName()` | REQ-001 | 0.85 | name:search,source | doc:search | path:chat,handler | cap-aligned:chat |
| lib/Service/Chat/ConversationManagementHandler.php | `__construct()` | REQ-001 | 1.0 | name:chat,conversation,handler | doc:chat,conversation,handler | path:chat,conve |
| lib/Service/Chat/ConversationManagementHandler.php | `generateConversationTitle()` | REQ-001 | 1.0 | name:conversation,first,generate,message,title | doc:conversation,first,generate |
| lib/Service/Chat/ConversationManagementHandler.php | `generateFallbackTitle()` | REQ-001 | 1.0 | name:generate,message,title | doc:generate,message,title | path:chat,conversatio |
| lib/Service/Chat/ConversationManagementHandler.php | `ensureUniqueTitle()` | REQ-001 | 1.0 | name:agent,conversation,title | doc:agent,conversation,title | path:chat,convers |
| lib/Service/Chat/ConversationManagementHandler.php | `checkAndSummarize()` | REQ-001 | 0.95 | name:conversation,if | doc:conversation,if | path:chat,conversation,handler | ca |
| lib/Service/Chat/ConversationManagementHandler.php | `generateSummary()` | REQ-001 | 0.95 | name:generate,messages | doc:generate,messages | path:chat,conversation,handler  |
| lib/Service/Chat/MessageHistoryHandler.php | `__construct()` | REQ-001 | 1.0 | name:chat,handler,history,message | doc:chat,handler,history,message | path:chat |
| lib/Service/Chat/MessageHistoryHandler.php | `buildMessageHistory()` | REQ-001 | 1.0 | name:history,llm,message | doc:history,llm,message | path:chat,handler,history,m |
| lib/Service/Chat/MessageHistoryHandler.php | `storeMessage()` | REQ-001 | 1.0 | name:database,message,store | doc:database,message,store | path:chat,handler,his |
| lib/Service/Chat/ResponseGenerationHandler.php | `__construct()` | REQ-001 | 1.0 | name:chat,generation,handler,response | doc:chat,generation,handler,response | p |
| lib/Service/Chat/ResponseGenerationHandler.php | `generateResponse()` | REQ-001 | 1.0 | name:generate,llm,response,using | doc:generate,llm,response,using | path:chat,g |
| lib/Service/Chat/ResponseGenerationHandler.php | `wrapToolsForStreaming()` | REQ-001 | 0.95 | name:active,chat | doc:active,chat | path:chat,generation,handler,response | cap |
| lib/Service/Chat/ResponseGenerationHandler.php | `chatHasTools()` | REQ-001 | 0.85 | name:chat,has | doc:chat | path:chat,generation,handler,response | cap-aligned:c |
| lib/Service/Chat/ResponseGenerationHandler.php | `callFireworksChatAPIWithHistory()` | REQ-001 | 1.0 | name:chat,full,history,message | doc:chat,full,history,message | path:chat,gener |
| lib/Service/Chat/StreamYieldChannel.php | `emitToolCall()` | REQ-001 | 0.85 | name:one,registered | doc:one,registered | path:chat | cap-aligned:chat |
| lib/Service/Chat/StreamYieldChannel.php | `emitToolResult()` | REQ-001 | 0.85 | name:one,registered | doc:one,registered | path:chat | cap-aligned:chat |
| lib/Service/Chat/ToolManagementHandler.php | `__construct()` | REQ-001 | 0.95 | name:chat,handler | doc:chat,handler | path:chat,handler | cap-aligned:chat |
| lib/Service/Chat/ToolManagementHandler.php | `getAgentTools()` | REQ-001 | 0.85 | name:agent,tools | doc:agent,tools | path:tool | cap-aligned:chat |
| lib/Service/Chat/ToolManagementHandler.php | `convertToolsToFunctions()` | REQ-001 | 0.85 | name:ai,tools | doc:ai,tools | path:tool | cap-aligned:chat |
| lib/Service/ChatService.php | `generateConversationTitle()` | REQ-001 | 0.95 | name:conversation,first,generate,message,title | doc:conversation,first,generate |
| _... 2 more_ | | | | |

#### content-versioning (3 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Controller/FilesController.php | `restoreVersion()` | REQ-017 | 0.85 | name:file,restore,specific,version | doc:file,restore,specific,version | path:fi |
| lib/Service/File/FileVersioningHandler.php | `__construct()` | REQ-017 | 0.95 | name:file,handler,versioning | doc:file,handler,versioning | path:file,handler,v |
| lib/Service/FileService.php | `getVersioningHandler()` | REQ-017 | 0.85 | name:file,handler,versioning | doc:file,handler,versioning | path:file |

#### extended-field-types (1 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Service/Schemas/PropertyValidatorHandler.php | `validateProperty()` | EFT-001 | 0.85 | name:property,validator | doc:property,validator | path:handler,property,validat |

#### geo-metadata-kaart (16 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Service/Geo/GeoFilter.php | `fromBbox()` | GEO-004 | 0.95 | name:bbox,bounding,box,filter | doc:bounding,box,filter | path:filter,geo |
| lib/Service/Geo/GeoFilter.php | `fromNearAndRadius()` | GEO-004 | 0.95 | name:filter,near,point,radius | doc:filter,point,radius | path:filter,geo |
| lib/Service/Geo/GeoFilter.php | `fromWithinGeometry()` | GEO-004 | 0.95 | name:filter,geo,geometry,json,polygon,within | doc:filter,geo,geometry,json,poly |
| lib/Service/Geo/GeoFilter.php | `fromIntersectsGeometry()` | GEO-004 | 0.95 | name:filter,geo,geometry,json | doc:filter,geo,geometry,json | path:filter,geo |
| lib/Service/Geo/GeoFilter.php | `assertGeoJsonGeometry()` | GEO-004 | 0.95 | name:geo,geometry,json | doc:geo,geometry,json | path:filter,geo |
| lib/Service/Geo/GeoFilterApplier.php | `applyAll()` | GEO-004 | 0.85 | name:apply,every,filter,rows | doc:every,filter,rows | path:filter |
| lib/Service/Geo/GeoFilterApplier.php | `rowMatchesAll()` | GEO-004 | 0.85 | name:every,filter,row | doc:every,filter,row | path:filter |
| lib/Service/Geo/GeoFilterApplier.php | `coerceGeometry()` | GEO-004 | 0.95 | name:geo,geometry,json | doc:geo,geometry,json | path:filter,geo |
| lib/Service/Geo/GeoFilterParser.php | `fromGeoSearchBody()` | GEO-004 | 0.95 | name:body,filters,geo,json,parse,post,search,spatial | doc:body,filters,geo,json |
| lib/Service/Geo/GeoSpatialEvaluator.php | `haversineMeters()` | GEO-012 | 0.95 | name:distance,haversine,lat,lon | doc:distance,lat,lon | path:geo,spatial |
| lib/Service/Geo/GeoSpatialEvaluator.php | `matchesBbox()` | GEO-012 | 0.95 | name:geometry,point,uses | doc:geometry,point,uses | path:geo,spatial |
| lib/Service/Geo/GeoSpatialEvaluator.php | `matchesNear()` | GEO-004 | 0.95 | name:distance,near,radius | doc:distance,near,radius | path:geo,spatial |
| lib/Service/Geo/GeoSpatialEvaluator.php | `matchesWithin()` | GEO-012 | 0.85 | name:polygon,within | doc:polygon,within | path:geo,spatial |
| lib/Service/Geo/GeoSpatialEvaluator.php | `extractRepresentativePoint()` | GEO-012 | 0.95 | name:geo,geometry,json,point | doc:geo,geometry,json | path:geo,spatial |
| lib/Service/Geo/GeoSpatialEvaluator.php | `pointInPolygonGeometry()` | GEO-012 | 0.95 | name:geometry,lat,lon,point,polygon | doc:lat,lon,point,polygon | path:geo,spati |
| lib/Service/Geo/GeoSpatialEvaluator.php | `pointInRing()` | GEO-012 | 0.95 | name:casting,point,polygon,ray | doc:casting,point,polygon,ray | path:geo,spatia |

#### nested-aggregations (24 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Listener/AggregationCacheInvalidationListener.php | `__construct()` | NAG-008 | 0.95 | name:aggregation,cache,invalidation | doc:aggregation,cache,invalidation | path: |
| lib/Listener/AggregationCacheInvalidationListener.php | `handle()` | NAG-008 | 0.85 | name:aggregation,cache | doc:aggregation,cache | path:aggregation,cache,invalida |
| lib/Listener/AggregationCacheInvalidationListener.php | `extractObject()` | NAG-008 | 0.85 | name:resolve,supported,types | doc:resolve,supported,types | path:listener |
| lib/Service/Aggregation/AggregationAnnotationValidator.php | `validate()` | NAG-002 | 0.85 | name:aggregation,annotation | doc:aggregation,annotation | path:aggregation,anno |
| lib/Service/Aggregation/AggregationCache.php | `__construct()` | NAG-008 | 0.85 | name:aggregation,cache | doc:aggregation,cache | path:aggregation,cache |
| lib/Service/Aggregation/AggregationCache.php | `get()` | NAG-008 | 0.85 | name:aggregation,result,up | doc:aggregation,result,up | path:aggregation |
| lib/Service/Aggregation/AggregationCache.php | `getAdhoc()` | NAG-008 | 0.85 | name:aggregation,result,up | doc:aggregation,result,up | path:aggregation |
| lib/Service/Aggregation/AggregationCache.php | `key()` | NAG-008 | 0.95 | name:cache,key,scope,so | doc:cache,key,scope,so | path:aggregation,cache |
| lib/Service/Aggregation/AggregationQuery.php | `create()` | NAG-002 | 0.85 | name:aggregation,query | doc:aggregation,query | path:aggregation,query |
| lib/Service/Aggregation/AggregationQuery.php | `getGroupByField()` | NAG-002 | 0.85 | name:field,group | doc:field,group | path:aggregation,query |
| lib/Service/Aggregation/AggregationRunner.php | `__construct()` | NAG-002 | 0.85 | name:aggregation,runner | doc:aggregation,runner | path:aggregation,runner |
| lib/Service/Aggregation/AggregationRunner.php | `run()` | NAG-005 | 0.85 | name:aggregation,named | doc:aggregation,named | path:aggregation,runner |
| lib/Service/Aggregation/AggregationRunner.php | `runAdhoc()` | NAG-005 | 0.95 | name:aggregation,entry,point | doc:aggregation,entry,point | path:aggregation,ph |
| lib/Service/Aggregation/AggregationRunner.php | `bucketInPhp()` | NAG-005 | 0.95 | name:bucket,fallback,php,postgres | doc:fallback,php,postgres | path:aggregation |
| lib/Service/Aggregation/AggregationRunner.php | `computeMetric()` | NAG-005 | 0.85 | name:rows,single | doc:rows,single | path:aggregation,php,runner |
| lib/Service/Aggregation/AggregationRunner.php | `computeGrouped()` | NAG-005 | 0.95 | name:field,group,metric | doc:field,group,metric | path:aggregation,runner |
| lib/Service/Aggregation/AggregationRunner.php | `tryNativeAggregation()` | NAG-005 | 0.85 | name:aggregation,sql | doc:aggregation,sql | path:aggregation,runner |
| lib/Service/Aggregation/AggregationRunner.php | `mysqlBucketExpression()` | NAG-005 | 0.85 | name:based,bucket | doc:based,bucket | path:aggregation,php,runner |
| lib/Service/Aggregation/AggregationRunner.php | `sqliteBucketExpression()` | NAG-005 | 0.85 | name:based,bucket | doc:based,bucket | path:aggregation,php,runner |
| lib/Service/Aggregation/AggregationRunner.php | `getAnnotation()` | NAG-002 | 0.95 | name:aggregations,annotation,openregister | doc:aggregations,annotation,openregi |
| lib/Service/Aggregation/ElasticsearchAggregationQueryBuilder.php | `translateFilters()` | NAG-005 | 0.95 | name:bool,filter,filters,query | doc:bool,filter,query | path:aggregation,query |
| lib/Service/Aggregation/ElasticsearchAggregationQueryBuilder.php | `collectOp()` | NAG-005 | 0.85 | name:field,not | doc:field,not | path:aggregation,query |
| lib/Service/Aggregation/SolrAggregationQueryBuilder.php | `translateFilters()` | NAG-005 | 0.95 | name:query,solr,translate | doc:query,solr,translate | path:query,solr |
| lib/Service/Aggregation/SolrAggregationQueryBuilder.php | `translateOp()` | NAG-005 | 0.95 | name:field,solr,translate | doc:field,solr,translate | path:query,solr |

#### oas-generation (6 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Controller/OasController.php | `generateInternal()` | REQ-002 | 0.85 | name:generate,generation,registers,single | doc:generation,registers,single | pa |
| lib/Service/OasService.php | `getBaseOas()` | REQ-001 | 0.85 | name:base,file,oas | doc:base,file,oas | path:oas |
| lib/Service/OasService.php | `getScopeDescription()` | REQ-001 | 0.85 | name:auth,group,readable | doc:auth,group,readable | path:oas |
| lib/Service/OasService.php | `enrichSchema()` | REQ-001 | 0.85 | name:endpoints,generation,included,oas | doc:endpoints,generation,included,oas | |
| lib/Service/OasService.php | `validateOasIntegrity()` | REQ-002 | 0.85 | name:api,integrity,oas,specification | doc:api,integrity,specification | path:oa |
| lib/Service/OasService.php | `validateServerUrls()` | REQ-001 | 0.85 | name:absolute,entry,every,server,servers,url | doc:absolute,entry,every,servers, |

#### seed-related-items (1 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Service/Configuration/ImportHandler.php | `processRelatedItems()` | REQ-06 | 0.85 | name:files,nextcloud | doc:files,nextcloud | path:configuration,handler |

#### text-extraction-eml (14 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Service/TextExtraction/EmlParser.php | `parse()` | ADR-005 | 1.0 | name:eml,file,parse,structured | doc:eml,file,parse,structured | path:eml,parser |
| lib/Service/TextExtraction/EmlParser.php | `parseMessage()` | ADR-005 | 1.0 | name:eml,message,parse | doc:eml,message,parse | path:eml,parser,text | cap-alig |
| lib/Service/TextExtraction/EmlParser.php | `flatten()` | ADR-005 | 1.0 | name:eml,plain,text | doc:eml,plain,text | path:eml,parser,text | cap-aligned:te |
| lib/Service/TextExtraction/EmlParser.php | `extractHeaders()` | ADR-005 | 1.0 | name:extract,headers,message | doc:extract,headers,message | path:eml,parser,tex |
| lib/Service/TextExtraction/EmlParser.php | `extractBody()` | UTF-8 | 1.0 | name:body,extract,parts | doc:body,extract,parts | path:eml,extraction,text | ca |
| lib/Service/TextExtraction/EmlParser.php | `extractAttachments()` | ADR-005 | 0.95 | name:attachment,eml,extract | doc:attachment,eml | path:eml,parser,text | cap-al |
| lib/Service/TextExtraction/EmlParser.php | `buildAttachment()` | ADR-005 | 1.0 | name:attachment,eml,mime | doc:attachment,eml,mime | path:eml,parser,text | cap- |
| lib/Service/TextExtraction/EmlParser.php | `parseNestedEml()` | ADR-005 | 1.0 | name:822,attachment,eml,message,parse,rfc | doc:822,attachment,message,parse,rfc |
| lib/Service/TextExtraction/EmlParser.php | `parseDate()` | ADR-005 | 0.95 | name:header,parse | doc:header,parse | path:eml,parser,text | cap-aligned:text |
| lib/Service/TextExtraction/EmlParser.php | `htmlToText()` | ADR-005 | 1.0 | name:html,plain,text | doc:html,plain,text | path:eml,parser,text | cap-aligned: |
| lib/Service/TextExtraction/EmlParser.php | `ensureUtf8()` | UTF-8 | 1.0 | name:encoding,string,transcode,utf | doc:encoding,string,transcode,utf | path:em |
| lib/Service/TextExtraction/EmlParser.php | `sanitisePiiForLogging()` | ADR-005 | 1.0 | name:005,adr,log,logging,output,per,pii | doc:005,adr,log,output,per | path:eml, |
| lib/Service/TextExtractionService.php | `extractEml()` | ADR-005 | 1.0 | name:eml,extract,file,flat,plain,text | doc:eml,extract,file,flat,plain,text | p |
| lib/Service/TextExtractionService.php | `parseEmlStructured()` | ADR-005 | 1.0 | name:eml,entry,files,parse,structured | doc:eml,entry,files,parse,structured | p |

#### tmlo-validation (1 methods)

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Service/TmloService.php | `calculateArchiefactiedatum()` | ISO-8601 | 0.95 | name:8601,duration,iso | doc:8601,duration,iso | path:tmlo | cap-aligned:tmlo |


### NEEDS-REVIEW (0.70–0.85) — top 50 lowest-confidence shown

| File | Method | REQ | Conf | Signal |
|---|---|---|---:|---|
| lib/Controller/AggregationController.php | `aggregate()` | nested-aggregations#NAG-005 | 0.75 | name:aggregation,named | doc:aggregation,named | path:aggregation |
| lib/Controller/BulkController.php | `delete()` | object-lifecycle#REQ-001 | 0.75 | name:bulk,operations | doc:bulk,operations | path:bulk |
| lib/Controller/BulkController.php | `save()` | object-lifecycle#REQ-001 | 0.75 | name:bulk,operations | doc:bulk,operations | path:bulk |
| lib/Controller/FilesController.php | `listVersions()` | content-versioning#REQ-017 | 0.75 | name:file,versions | doc:file,versions | path:files |
| lib/Controller/ObjectsController.php | `geoSearch()` | geo-metadata-kaart#GEO-004 | 0.75 | name:api,endpoint,post | doc:api,endpoint,post |
| lib/Controller/ObjectsController.php | `applyGeoQueryFilters()` | geo-metadata-kaart#GEO-004 | 0.75 | name:filters,geo,query,result | doc:filters,geo,query,result |
| lib/Controller/ObjectsController.php | `flattenGeoParams()` | geo-metadata-kaart#GEO-004 | 0.75 | name:back,into,query | doc:back,into,query |
| lib/Service/Configuration/ImportHandler.php | `importSeedData()` | seed-related-items#REQ-01 | 0.75 | name:import,seed | doc:import,seed | path:import |
| lib/Service/ConfigurationService.php | `importFromJson()` | seed-related-items#REQ-01 | 0.75 | name:file,import,json | doc:file,import,json |
| lib/Service/FileService.php | `addFile()` | seed-related-items#REQ-06 | 0.75 | name:add,file,folder | doc:file,folder | path:file |
| lib/Service/ImportService.php | `processMultiSchemaSpreadsheetAsync()` | object-lifecycle#REQ-004 | 0.75 | name:batch,performance | doc:batch,performance | path:import |
| lib/Service/ImportService.php | `processSpreadsheetBatch()` | object-lifecycle#REQ-004 | 0.75 | name:batch,performance | doc:batch,performance | path:import |
| lib/Service/ImportService.php | `processCsvSheet()` | object-lifecycle#REQ-004 | 0.75 | name:batches,import | doc:batches,import | path:import |
| lib/Service/OasService.php | `createOas()` | oas-generation#REQ-001 | 0.75 | name:api,oas,specification | doc:api,specification | path:oas |
| lib/Service/OasService.php | `logValidationIssues()` | actions#REQ-002 | 0.75 | name:at,level | doc:at,level | path:php |
| lib/Service/OasService.php | `extractSchemaGroups()` | deprecate-published-metadata#REQ-3 | 0.75 | name:level,rbac,rules | doc:level,rbac,rules |
| lib/Service/OasService.php | `addCrudPaths()` | oas-generation#REQ-001 | 0.75 | name:crud,paths | doc:crud,paths | path:oas |
| lib/Service/OasService.php | `getPropertyType()` | mock-registers#ADR-006 | 0.75 | name:api,definition,property | doc:api,definition,property |
| lib/Service/OasService.php | `createGetCollectionOperation()` | oas-validation#API-01 | 0.75 | name:collection,operation | doc:collection,operation | path:oas |
| lib/Service/OasService.php | `createPutOperation()` | oas-validation#API-01 | 0.75 | name:operation,put | doc:operation,put | path:oas |
| lib/Service/OasService.php | `createPostOperation()` | oas-validation#API-01 | 0.75 | name:operation,post | doc:operation,post | path:oas |
| lib/Service/OasService.php | `createPostFileOperation()` | oas-generation#REQ-001 | 0.75 | name:file,post | doc:file,post | path:oas |
| lib/Service/OasService.php | `pascalCase()` | extended-field-types#EFT-004 | 0.75 | name:case,string | doc:case,string | path:oas |
| lib/Service/OasService.php | `sanitizeSchemaName()` | oas-validation#API-01 | 0.75 | name:api,names | doc:api,names | path:oas |
| lib/Service/OasService.php | `validateTagConsistency()` | object-lifecycle#REQ-001 | 0.75 | name:against,check,operations,validate | doc:against,check,operations |
| lib/Service/OasService.php | `validateSchemaReferences()` | oas-validation#API-42 | 0.75 | name:references,validate | doc:references,validate | path:oas |
| lib/Service/OasService.php | `expandRolesForOas()` | oas-generation#REQ-001 | 0.75 | name:generation,oas | doc:generation,oas | path:oas |
| lib/Service/Object/SaveObjects/TransformationHandler.php | `transformObjectsToDatabaseFormatInPlace()` | object-lifecycle#REQ-001 | 0.75 | name:database,format | doc:database,format | path:handler |
| lib/Service/Object/SchemaTypeConverter.php | `convertValue()` | extended-field-types#EFT-001 | 0.75 | name:convert,converter,driven | doc:converter,driven | path:converter |
| lib/Service/Object/SchemaTypeConverter.php | `convertString()` | extended-field-types#EFT-001 | 0.75 | name:convert,string | doc:convert,string | path:converter |
| lib/Service/ObjectService.php | `extractUuidAndNormalizeObject()` | object-lifecycle#REQ-001 | 0.75 | name:array,format,uuid | doc:array,format,uuid |
| lib/Service/ObjectService.php | `saveObjects()` | object-lifecycle#REQ-004 | 0.75 | name:bulk,operations,performance,processing | doc:bulk,operations,performance,pr |
| lib/Service/ObjectService.php | `validateObjectsBySchema()` | object-lifecycle#REQ-002 | 0.75 | name:handler,not,validate,validation | doc:handler,not,validate,validation |
| lib/Service/RetentionService.php | `lookupSelectielijstEntry()` | archival-destruction-workflow#REQ-001 | 0.75 | name:entry,selectielijst | doc:entry,selectielijst | path:retention |
| lib/Service/RetentionService.php | `findEligibleForDestruction()` | archival-destruction-workflow#REQ-001 | 0.75 | name:destruction,eligible | doc:destruction,eligible | path:retention |
| lib/Service/Chat/ResponseGenerationHandler.php | `invokeChat()` | chat-ai#REQ-001 | 0.75 | inherited-from-__construct |
| lib/Service/Chat/ResponseGenerationHandler.php | `streamChat()` | chat-ai#REQ-001 | 0.75 | inherited-from-__construct |
| lib/Service/Object/SchemaTypeConverter.php | `convertBoolean()` | extended-field-types#EFT-001 | 0.75 | inherited-from-convertValue |
| lib/Service/Object/SchemaTypeConverter.php | `convertInteger()` | extended-field-types#EFT-001 | 0.75 | inherited-from-convertValue |
| lib/Service/Object/SchemaTypeConverter.php | `convertNumber()` | extended-field-types#EFT-001 | 0.75 | inherited-from-convertValue |
| lib/Service/Object/SchemaTypeConverter.php | `convertArrayOrObject()` | extended-field-types#EFT-001 | 0.75 | inherited-from-convertValue |
| lib/Service/Geo/GeoSpatialEvaluator.php | `matchesIntersects()` | geo-metadata-kaart#GEO-012 | 0.75 | inherited-from-haversineMeters |
| lib/Service/Geo/GeoSpatialEvaluator.php | `ringCentroid()` | geo-metadata-kaart#GEO-012 | 0.75 | inherited-from-haversineMeters |
| lib/Service/OasService.php | `extractGroupFromRule()` | oas-generation#REQ-001 | 0.75 | inherited-from-__construct |
| lib/Service/OasService.php | `applyRbacToOperation()` | oas-generation#REQ-001 | 0.75 | inherited-from-__construct |
| lib/Service/OasService.php | `addExtendedPaths()` | oas-generation#REQ-001 | 0.75 | inherited-from-__construct |
| lib/Service/OasService.php | `createCommonQueryParameters()` | oas-generation#REQ-001 | 0.75 | inherited-from-__construct |
| lib/Service/OasService.php | `createGetOperation()` | oas-generation#REQ-001 | 0.75 | inherited-from-__construct |
| lib/Service/OasService.php | `createDeleteOperation()` | oas-generation#REQ-001 | 0.75 | inherited-from-__construct |
| lib/Service/OasService.php | `createLogsOperation()` | oas-generation#REQ-001 | 0.75 | inherited-from-__construct |

_(Full list of 56 NEEDS-REVIEW entries is in `coverage-report.json` under `buckets.bucket_1` filtered by `needs_review: true`.)_

## Bucket 2a — Known capability, no REQ (extend specs via `/opsx-reverse-spec --extend`)

### cluster: `file-actions` (268 methods)

- `lib/Controller/FileSidebarController.php` — `__construct()`, `getObjectsForFile()`, `getExtractionStatus()`
- `lib/Controller/FileTextController.php` — `getChunkingStats()`, `anonymizeFile()`, `__construct()`, `getFileText()`, `extractFileText()`, `bulkExtract()`, `getStats()`, `deleteFileText()` + more
- `lib/Controller/FilesController.php` — `rename()`, `copy()`, `move()`, `lock()`, `unlock()`, `preview()`, `updateLabels()`, `show()` + more
- `lib/Controller/Settings/FileSettingsController.php` — `__construct()`, `getFileSettings()`, `updateFileSettings()`, `testDolphinConnection()`, `testPresidioConnection()`, `testOpenAnonymiserConnection()`, `reindexFiles()`, `getFileIndexStats()` + more
- `lib/Event/FileCopiedEvent.php` — `__construct()`
- `lib/Event/FileLockedEvent.php` — `__construct()`
- `lib/Event/FileMovedEvent.php` — `__construct()`
- `lib/Event/FileRenamedEvent.php` — `__construct()`
- `lib/Event/FileUnlockedEvent.php` — `__construct()`
- `lib/Event/UserProfileUpdatedEvent.php` — `__construct()`
- _... 31 more files_

### cluster: `search` (148 methods)

- `lib/Controller/FileSearchController.php` — `semanticSearch()`, `hybridSearch()`
- `lib/Controller/SearchTrailController.php` — `popularTerms()`, `paginate()`, `statistics()`, `activity()`, `registerSchemaStats()`, `userAgentStats()`, `extractRequestParameters()`, `index()` + more
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchDocumentIndexer.php` — `__construct()`, `deleteObject()`, `clearIndex()`, `indexObject()`, `bulkIndexObjects()`
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchHttpClient.php` — `__construct()`, `get()`, `delete()`, `getConfig()`, `buildBaseUrl()`, `post()`, `put()`, `getHttpClient()` + more
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchIndexManager.php` — `__construct()`, `deleteIndex()`, `refreshIndex()`, `createIndex()`, `getActiveIndexName()`, `getIndexStats()`, `indexExists()`, `ensureIndex()`
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchQueryExecutor.php` — `getDocumentCount()`, `__construct()`, `search()`, `buildElasticsearchQuery()`
- `lib/Service/Index/Backends/ElasticsearchBackend.php` — `indexObject()`, `deleteObject()`, `deleteByQuery()`, `getDocumentCount()`, `commit()`, `search()`, `reindexAll()`, `testConnection()` + more
- `lib/Service/Index/Backends/Solr/SolrFacetProcessor.php` — `buildFacetQuery()`
- `lib/Service/Index/SearchBackendInterface.php` — `testConnection()`, `deleteByQuery()`, `commit()`, `clearIndex()`, `listCollections()`, `getFields()`, `reindexAll()`, `bulkIndexObjects()` + more
- `lib/Service/Schemas/FacetCacheHandler.php` — `clearDistributedFacetCaches()`
- _... 12 more files_

### cluster: `openregister-app-manifest` (109 methods)

- `lib/Controller/ManifestController.php` — `loadBundledManifest()`
- `lib/Controller/RegistersController.php` — `index()`, `show()`, `create()`, `update()`, `patch()`, `destroy()`, `schemas()`, `objects()` + more
- `lib/Controller/SchemasController.php` — `index()`, `show()`, `create()`, `update()`, `patch()`, `destroy()`, `related()`, `stats()` + more
- `lib/Event/RegisterCreatedEvent.php` — `__construct()`
- `lib/Event/RegisterDeletedEvent.php` — `__construct()`
- `lib/Event/RegisterUpdatedEvent.php` — `__construct()`
- `lib/Event/SchemaCreatedEvent.php` — `__construct()`
- `lib/Event/SchemaDeletedEvent.php` — `__construct()`
- `lib/Event/SchemaUpdatedEvent.php` — `__construct()`
- `lib/Service/GraphQL/SchemaGenerator.php` — `getObjectType()`
- _... 35 more files_

### cluster: `actions` (96 methods)

- `lib/BackgroundJob/FileTextExtractionJob.php` — `run()`, `__construct()`
- `lib/Controller/ActionsController.php` — `requireAdmin()`, `index()`, `show()`, `create()`, `update()`, `patch()`, `destroy()`, `test()` + more
- `lib/Event/ActionCreatedEvent.php` — `__construct()`
- `lib/Event/ActionDeletedEvent.php` — `__construct()`
- `lib/Event/ActionUpdatedEvent.php` — `__construct()`
- `lib/Service/ActionService.php` — `testAction()`, `migrateFromHooks()`, `getNestedValue()`
- `lib/Service/TextExtraction/EmlAttachment.php` — `jsonSerialize()`
- `lib/Service/TextExtraction/EmlBody.php` — `jsonSerialize()`
- `lib/Service/TextExtraction/EmlParser.php` — `stripAngleBrackets()`, `splitAddressList()`, `resolveFilename()`, `sanitiseFilename()`, `getParser()`
- `lib/Service/TextExtraction/EmlStructure.php` — `jsonSerialize()`
- _... 7 more files_

### cluster: `notificatie-engine` (88 methods)

- `lib/BackgroundJob/NotificationQueueFlushJob.php` — `run()`, `processGroup()`, `resolveDigestSpec()`
- `lib/BackgroundJob/ScheduledNotificationJob.php` — `isDue()`, `markFired()`, `matchesFilter()`, `__construct()`, `run()`, `processSchema()`, `stateKey()`, `fire()`
- `lib/Controller/NotificationHistoryController.php` — `extractFilters()`, `resolveLimit()`, `resolveOffset()`
- `lib/Controller/NotificationSubscriptionsController.php` — `index()`, `create()`, `destroy()`, `resolveUserId()`, `coerceNullableInt()`
- `lib/Listener/AnnotationNotificationListener.php` — `handle()`, `extractObject()`, `__construct()`
- `lib/Notification/AnnotationNotifier.php` — `__construct()`, `getName()`, `getID()`, `prepare()`
- `lib/Notification/Notifier.php` — `__construct()`, `getName()`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php` — `__construct()`, `rateLimitAllows()`, `emitTalk()`, `emitWebhook()`, `numericConditionMatches()`, `resolveRecipients()`, `emitEmail()`, `emitActivity()` + more
- `lib/Service/Notification/NotificationAnnotationValidator.php` — `validate()`, `validateOrganisationGate()`
- `lib/Service/Notification/NotificationCoalescer.php` — `__construct()`, `shouldDispatch()`, `inspect()`, `persist()`, `isEnabled()`, `resolveWindowSeconds()`, `resolveMaxEvents()`, `key()`
- _... 9 more files_

### cluster: `ai-mcp` (77 methods)

- `lib/Controller/McpServerController.php` — `handleNotification()`, `dispatch()`, `handleToolCall()`, `jsonRpcSuccess()`, `handleInitialize()`, `handleResourceRead()`, `jsonRpcError()`
- `lib/Event/ToolRegistrationEvent.php` — `__construct()`
- `lib/Mcp/BuiltIn/ObjectsToolProvider.php` — `getAppId()`, `getTools()`, `invokeTool()`, `listObjects()`, `getObject()`, `createObject()`, `updateObject()`, `deleteObject()` + more
- `lib/Mcp/BuiltIn/RegistersToolProvider.php` — `getAppId()`, `getTools()`, `invokeTool()`, `listRegisters()`, `getRegister()`, `createRegister()`, `updateRegister()`, `deleteRegister()` + more
- `lib/Mcp/BuiltIn/SchemasToolProvider.php` — `getAppId()`, `getTools()`, `invokeTool()`, `listSchemas()`, `getSchema()`, `createSchema()`, `updateSchema()`, `deleteSchema()` + more
- `lib/Mcp/IMcpToolProvider.php` — `getTools()`, `invokeTool()`
- `lib/Service/Mcp/McpProtocolService.php` — `__construct()`, `initialize()`, `ping()`, `createSession()`, `validateSession()`, `destroySession()`
- `lib/Service/Mcp/McpResourcesService.php` — `__construct()`, `listResources()`, `listTemplates()`, `readResource()`, `parseUri()`, `readRegisters()`, `readSchemas()`, `readObjects()`
- `lib/Service/Mcp/McpToolsService.php` — `listTools()`, `callTool()`, `invokeTool()`, `findProviderForTool()`, `getProviders()`, `addProvider()`
- `lib/Service/McpDiscoveryService.php` — `getCapabilityIds()`, `getBaseUrl()`
- _... 6 more files_

### cluster: `data-import-export` (58 methods)

- `lib/Service/Configuration/ExportHandler.php` — `setWorkflowEngineRegistry()`, `setDeployedWorkflowMapper()`, `getLastNumericSegment()`, `exportWorkflowsForSchema()`, `exportSchema()`
- `lib/Service/Configuration/ImportHandler.php` — `setWorkflowEngineRegistry()`, `setDeployedWorkflowMapper()`, `setUserSession()`, `processWorkflowDeployment()`, `handleNextcloudAppDependencies()`, `getDuplicateRegisterInfo()`, `getDuplicateSchemaInfo()`, `minimalSchemaShapeMetaSchema()` + more
- `lib/Service/ExportService.php` — `buildTemplateSpreadsheet()`, `buildTemplateCsv()`, `isUserAdmin()`, `isRelationProperty()`, `collectUuids()`, `populateSheet()`, `fetchObjectsForExport()`, `identifyNameCompanionColumns()` + more
- `lib/Service/ImportService.php` — `clearCaches()`, `softDeleteByImportJobId()`, `isUserAdmin()`, `transformCsvRowToObject()`, `transformDateTimeValue()`, `transformSelfProperty()`, `transformExcelRowToObject()`, `transformValueByType()` + more
- `src/modals/configuration/ExportConfiguration.vue` — `closeModal()`
- `src/modals/configuration/ImportConfiguration.vue` — `checkTokenAvailability()`
- `src/modals/register/ExportRegister.vue` — `closeModal()`
- `src/modals/register/ImportRegister.vue` — `getFileExtension()`
- `src/views/account/sections/ExportSection.vue` — `exportData()`

### cluster: `auth-system` (57 methods)

- `lib/Exception/NotAuthorizedException.php` — `__construct()`
- `lib/Service/AuthorizationService.php` — `validatePayload()`, `authorizeBasic()`, `authorizeOAuth()`, `corsAfterController()`, `authorizeApiKey()`, `__construct()`, `base64urlDecode()`, `verifyHmac()` + more
- `lib/Service/Index/Backends/SolrBackend.php` — `testConnection()`, `deleteObject()`, `deleteByQuery()`, `searchObjectsPaginated()`, `getDocumentCount()`, `commit()`, `optimize()`, `clearIndex()` + more
- `lib/Service/PropertyRbacHandler.php` — `userQualifiesForGroup()`, `isAdmin()`, `__construct()`, `canReadProperty()`, `canUpdateProperty()`, `filterReadableProperties()`, `getUnauthorizedProperties()`, `checkPropertyAccess()` + more
- `src/components/RbacTable.vue` — `hasPermission()`, `if()`
- `src/views/settings/sections/PermissionMatrix.vue` — `loadData()`
- `src/views/settings/sections/RbacConfiguration.vue` — `showRebaseDialog()`

### cluster: `contacts-actions` (45 methods)

- `lib/Controller/ContactsController.php` — `__construct()`, `index()`, `create()`, `update()`, `destroy()`, `objects()`, `validateObject()`, `match()` + more
- `lib/Service/ContactMatchingService.php` — `__construct()`, `matchByEmail()`, `matchByName()`, `matchByOrganization()`, `matchContact()`, `getRelatedObjectCounts()`, `invalidateCache()`, `invalidateCacheForObject()` + more
- `lib/Service/ContactService.php` — `__construct()`, `getContactsForObject()`, `linkContact()`, `createAndLinkContact()`, `updateRole()`, `unlinkContact()`, `getObjectsForContact()`, `deleteLinksForObject()` + more
- `lib/Service/Integration/Providers/ContactsProvider.php` — `getId()`, `getLabel()`, `getIcon()`, `getGroup()`, `getRequiredApp()`, `getStorageStrategy()`, `isEnabled()`, `list()` + more
- `src/components/object-relations/ContactsTab.vue` — `fetchContacts()`
- `src/store/modules/object-relations/contacts.js` — `useContactRelationsStore()`

### cluster: `mail-sidebar` (45 methods)

- `lib/Controller/EmailsController.php` — `index()`, `create()`, `destroy()`, `validateObject()`
- `lib/Service/EmailService.php` — `unlinkEmail()`, `deleteLinksForObject()`, `isMailAvailable()`, `findMessageIdsBySender()`, `getMailLinkedSchemas()`, `buildMailboxSubquery()`
- `lib/Service/Integration/Providers/EmailProvider.php` — `getId()`, `getLabel()`, `getIcon()`, `getGroup()`, `getRequiredApp()`, `getStorageStrategy()`, `isEnabled()`, `list()` + more
- `src/components/object-relations/EmailsTab.vue` — `fetchEmails()`
- `src/mail-sidebar.js` — `isMailAppPage()`, `mountSidebar()`
- `src/mail-sidebar/MailSidebar.vue` — `toggleCollapsed()`
- `src/mail-sidebar/api/emailLinks.js` — `fetchLinkedObjects()`, `fetchSenderObjects()`, `createQuickLink()`, `deleteEmailLink()`, `searchObjects()`
- `src/mail-sidebar/components/EntitiesTab.vue` — `formatType()`
- `src/mail-sidebar/components/LinkObjectDialog.vue` — `onSearchInput()`, `if()`
- `src/mail-sidebar/components/ObjectsTab.vue` — `objectUrl()`
- _... 4 more files_

### cluster: `aggregations-backend-native` (44 methods)

- `lib/Controller/AggregationController.php` — `__construct()`, `timeseries()`
- `lib/Listener/AggregationThresholdListener.php` — `compare()`, `loadSchema()`, `__construct()`, `handle()`, `evaluate()`, `extractObject()`
- `lib/Service/Aggregation/AggregationAnnotationValidator.php` — `validateCrossSchemaSpec()`
- `lib/Service/Aggregation/AggregationCache.php` — `set()`, `setAdhoc()`, `adhocName()`, `evictForSchema()`, `rbacScopeHash()`
- `lib/Service/Aggregation/AggregationQuery.php` — `hasDateBucket()`, `toArray()`, `canonicaliseFilter()`, `assertValidDateBucket()`, `isGrouped()`
- `lib/Service/Aggregation/AggregationRunner.php` — `runAdhocByRef()`, `findSchema()`, `avg()`, `detectBackendName()`, `truncateTimestamp()`, `reduceNumeric()`, `checkOp()`, `normaliseForCompare()` + more
- `lib/Service/Aggregation/SolrAggregationQueryBuilder.php` — `quote()`, `bound()`
- `lib/Service/Aggregation/TimeseriesRequestValidator.php` — `allowedFields()`, `fieldFormat()`
- `lib/Service/Aggregation/WidgetAnnotationValidator.php` — `validate()`, `validateOne()`

### cluster: `search-index` (35 methods)

- `lib/Service/Index/Backends/Solr/SolrCollectionManager.php` — `collectionExists()`
- `lib/Service/Index/Backends/Solr/SolrDocumentIndexer.php` — `indexDocuments()`
- `lib/Service/Index/Backends/Solr/SolrFacetProcessor.php` — `processFacetResponse()`
- `lib/Service/Index/Backends/Solr/SolrHttpClient.php` — `initializeHttpClient()`
- `lib/Service/Index/Backends/Solr/SolrQueryExecutor.php` — `__construct()`, `search()`, `buildSolrQuery()`, `translateSortField()`, `convertToPaginatedFormat()`, `inspectIndex()`
- `lib/Service/Index/BulkIndexer.php` — `bulkIndexFromDatabase()`
- `lib/Service/Index/ConfigurationHandler.php` — `__construct()`, `getTenantSpecificCollectionName()`, `getConfigStatus()`
- `lib/Service/Index/DocumentBuilder.php` — `flattenRelationsForSolr()`
- `lib/Service/Index/SchemaHandler.php` — `getCoreMetadataFields()`, `applySolrFields()`
- `lib/Service/Index/SchemaMapper.php` — `mapToBackendSchema()`, `mapFieldType()`
- _... 3 more files_

### cluster: `oas-generation` (33 methods)

- `lib/Controller/OasController.php` — `boolQueryParam()`
- `lib/Exception/OasValidationException.php` — `__construct()`, `getReport()`
- `lib/Middleware/OasValidationFailureException.php` — `__construct()`, `getErrors()`
- `lib/Middleware/OasValidationMiddleware.php` — `beforeController()`, `afterException()`, `resolveOperationSchema()`
- `lib/Service/Oas/OasETagComputer.php` — `hash()`, `canonicalise()`, `matches()`
- `lib/Service/Oas/OasRequestValidator.php` — `isValid()`, `collectErrors()`
- `lib/Service/Oas/OasValidationReport.php` — `addWarning()`, `filterBySeverity()`, `addError()`, `addAutoCorrection()`, `getIssues()`, `getErrors()`, `getWarnings()`, `getAutoCorrections()` + more
- `lib/Service/Oas/ProblemDetailsBuilder.php` — `conflict()`, `validationFailed()`, `notFound()`
- `lib/Service/OasService.php` — `__construct()`, `getLastValidationReport()`, `sanitizePropertyDefinition()`, `validateAgainstMetaSchema()`, `validateNlGovRules()`

### cluster: `approval-workflow` (27 methods)

- `lib/BackgroundJob/ScheduledWorkflowJob.php` — `run()`, `evaluateSchedule()`
- `lib/Controller/ScheduledWorkflowController.php` — `__construct()`, `index()`, `create()`, `update()`, `destroy()`, `show()`
- `lib/Controller/WorkflowEngineController.php` — `update()`, `destroy()`, `show()`, `testHook()`
- `lib/Service/WorkflowEngineRegistry.php` — `resolveAdapterById()`, `getEngines()`, `getEnginesByType()`, `createEngine()`, `updateEngine()`, `deleteEngine()`, `getEngine()`, `discoverEngines()`
- `src/components/workflow/ApprovalChainPanel.vue` — `fetchChains()`
- `src/components/workflow/ApprovalStepList.vue` — `fetchSteps()`
- `src/components/workflow/HookForm.vue` — `save()`
- `src/components/workflow/ScheduledWorkflowPanel.vue` — `fetchSchedules()`
- `src/components/workflow/TestHookDialog.vue` — `runTest()`
- `src/components/workflow/WorkflowExecutionPanel.vue` — `fetchExecutions()`
- _... 1 more files_

### cluster: `calendar-integration` (27 methods)

- `lib/Calendar/CalendarEventTransformer.php` — `resolveStatus()`
- `lib/Controller/CalendarEventsController.php` — `create()`, `destroy()`, `validateObject()`, `__construct()`, `index()`, `link()`
- `lib/Exception/NoVtodoCalendarException.php` — `__construct()`
- `lib/Service/CalendarEventService.php` — `unlinkEventsForObject()`, `getEventsForObject()`, `createEvent()`, `linkEvent()`, `unlinkEvent()`, `findUserCalendar()`, `veventToArray()`, `escapeIcalText()`
- `lib/Service/Integration/Providers/CalendarProvider.php` — `getId()`, `getLabel()`, `getIcon()`, `getGroup()`, `getRequiredApp()`, `getStorageStrategy()`, `isEnabled()`, `list()` + more
- `src/views/schema/CalendarProviderTab.vue` — `loadConfig()`

### cluster: `audit-trail-immutable` (26 methods)

- `lib/Controller/AuditTrailController.php` — `requireAdmin()`
- `lib/Service/AuthorizationAuditService.php` — `logSchemaAuthorizationChange()`
- `lib/Service/File/FileAuditHandler.php` — `getCurrentUserId()`, `__construct()`, `logDownload()`, `logBulkDownload()`, `logFileAction()`
- `lib/Service/Integration/BuiltinProviders/AuditTrailProvider.php` — `getId()`, `getLabel()`, `getIcon()`, `getRequiredApp()`, `getStorageStrategy()`, `getGroup()`, `isEnabled()`, `list()` + more
- `src/entities/auditTrail/auditTrail.mock.ts` — `mockAuditTrailData()`, `mockAuditTrail()`
- `src/modals/logs/AuditTrailChanges.vue` — `closeDialog()`
- `src/modals/logs/AuditTrailDetails.vue` — `closeDialog()`
- `src/modals/logs/ClearAuditTrails.vue` — `closeDialog()`
- `src/modals/logs/DeleteAuditTrail.vue` — `closeDialog()`
- `src/modals/objectAuditTrail/ViewObjectAuditTrail.vue` — `closeDialog()`
- _... 3 more files_

### cluster: `object-lifecycle` (25 methods)

- `lib/Lifecycle/GuardResult.php` — `allow()`, `deny()`, `isAllowed()`, `getMessage()`, `__construct()`
- `lib/Lifecycle/LifecycleGuardInterface.php` — `check()`
- `lib/Listener/LifecycleInitialStateListener.php` — `handle()`, `loadSchema()`, `getLifecycleAnnotation()`, `__construct()`
- `lib/Listener/LifecycleValidationListener.php` — `reject()`, `loadSchema()`, `getLifecycleAnnotation()`, `__construct()`, `handle()`, `findTransitionByTarget()`
- `lib/Service/Lifecycle/LifecycleAnnotationValidator.php` — `validate()`
- `lib/Service/Lifecycle/LifecycleGuardRegistry.php` — `__construct()`, `resolve()`
- `lib/Service/Lifecycle/TransitionEngine.php` — `__construct()`, `transition()`, `loadSchema()`, `getLifecycleAnnotation()`, `availableActions()`
- `lib/Service/TenantLifecycleService.php` — `isValidStatus()`

### cluster: `chat-ai` (19 methods)

- `lib/Controller/ChatController.php` — `page()`
- `lib/Controller/ChatHealthController.php` — `health()`
- `lib/Controller/ChatStreamController.php` — `clearOutputBuffers()`, `emitSseHeaders()`, `emitAndExit()`, `safeShutdown()`, `emitSseEvent()`, `now()`, `forwardWithHeartbeat()`, `pickFallbackAgentForUser()`
- `lib/Service/Chat/StreamYieldChannel.php` — `emitToken()`, `emitHeartbeat()`, `onToolCall()`, `onToolResult()`, `onHeartbeat()`
- `lib/Service/Chat/ToolManagementHandler.php` — `convertFunctionsToFunctionInfo()`
- `lib/Service/ChatService.php` — `testChat()`
- `src/sidebars/chat/ChatSideBar.vue` — `isActive()`
- `src/views/chat/ChatIndex.vue` — `showAgentSelector()`

### cluster: `retention-management` (18 methods)

- `lib/BackgroundJob/AvgRetentionJob.php` — `__construct()`, `run()`
- `lib/BackgroundJob/RealtimeEventRetentionJob.php` — `__construct()`, `run()`
- `lib/Controller/RetentionController.php` — `checkDualApprovalRequired()`
- `lib/Service/AvgRetentionService.php` — `__construct()`, `runRetentionPass()`, `findOverdueObjectsForActivity()`, `erasePastRetention()`, `processActivity()`, `computeCutoff()`, `loadCandidate()`
- `lib/Service/RetentionService.php` — `determineBrondatum()`, `validateNotImmutable()`, `extractSelectielijstBron()`
- `lib/Service/Settings/ObjectRetentionHandler.php` — `getVersionInfoOnly()`, `convertToBoolean()`
- `src/views/settings/sections/RetentionConfiguration.vue` — `showRebaseDialog()`

### cluster: `tmlo-metadata` (16 methods)

- `lib/Controller/TmloController.php` — `__construct()`, `exportSingle()`, `exportBatch()`, `summary()`
- `lib/Service/TmloService.php` — `__construct()`, `isTmloEnabled()`, `getSchemaDefaults()`, `populateDefaults()`, `validateFieldValues()`, `validateStatusTransition()`, `generateMdtoXml()`, `generateBatchMdtoXml()` + more

### cluster: `activity-provider` (14 methods)

- `lib/Activity/Setting/RegisterSetting.php` — `__construct()`, `getIdentifier()`, `getName()`, `getGroupIdentifier()`, `getGroupName()`, `getPriority()`, `canChangeStream()`, `isDefaultEnabledStream()` + more
- `lib/Service/Integration/Providers/ActivityProvider.php` — `list()`, `health()`
- `src/dialogs/avg/EditActivityDialog.vue` — `makeForm()`
- `src/views/account/sections/ActivitySection.vue` — `loadActivity()`

### cluster: `data-integrity-relations` (10 methods)

- `lib/Controller/RelationsController.php` — `__construct()`, `index()`, `gatherRelations()`, `buildTimeline()`, `validateObject()`
- `src/components/object-relations/DeckTab.vue` — `fetchCards()`
- `src/components/object-relations/EventsTab.vue` — `fetchEvents()`
- `src/components/object-relations/RelationsTab.vue` — `fetchRelations()`
- `src/store/modules/object-relations/deck.js` — `useDeckRelationsStore()`
- `src/store/modules/object-relations/events.js` — `useEventRelationsStore()`

### cluster: `archival-destruction-workflow` (10 methods)

- `lib/BackgroundJob/ArchivalRetentionTask.php` — `sweepSchema()`, `run()`, `extractArchivalAnnotation()`, `stripMetadataColumns()`
- `lib/Service/Archival/ArchivalAnnotationValidator.php` — `validateRule()`
- `lib/Service/Archival/RetentionConditionEvaluator.php` — `parseLiteral()`, `compare()`
- `lib/Service/Archival/RetentionEvaluator.php` — `addDuration()`
- `lib/Service/ArchivalService.php` — `setRetentionMetadata()`, `extendRetentionForObject()`

### cluster: `edepot-transfer` (6 methods)

- `lib/BackgroundJob/TransferExecutionJob.php` — `resolveTransport()`
- `lib/Controller/Settings/EdepotSettingsController.php` — `testEdepotConnection()`, `resolveTransport()`
- `lib/Service/Edepot/Transport/OpenConnectorTransport.php` — `__construct()`
- `lib/Service/Edepot/Transport/TransportResult.php` — `__construct()`, `getTransferReference()`

### cluster: `geo-metadata-kaart` (6 methods)

- `lib/Service/Geo/GeoFilterApplier.php` — `extractGeometry()`
- `lib/Service/Geo/GeoSpatialEvaluator.php` — `extractPolygons()`
- `src/modals/object/MergeObject.vue` — `initializeMerge()`, `if()`
- `src/modals/organisation/ManageOrganisationRoles.vue` — `initializeOrganisationItem()`, `if()`

### cluster: `files-sidebar-tabs` (4 methods)

- `src/components/EntitiesSidebar.vue` — `handleSearchInput()`
- `src/components/WebhooksSidebar.vue` — `handleSearchInput()`
- `src/sidebars/dashboard/DashboardSideBar.vue` — `handleRegisterChange()`
- `src/sidebars/deleted/DeletedSideBar.vue` — `applyFilters()`

### cluster: `computed-fields` (4 methods)

- `lib/Service/Object/SaveObject/ComputedFieldHandler.php` — `extractTwigVariables()`, `dfsForCycles()`, `detectCircularDependencies()`, `canonicaliseCycle()`

### cluster: `content-versioning` (3 methods)

- `lib/Event/FileVersionRestoredEvent.php` — `__construct()`
- `src/components/shared/VersionInfoCard.vue` — `handleUpdateClick()`, `if()`

### cluster: `saas-multi-tenant` (2 methods)

- `lib/Service/TenantKeyService.php` — `fetchActiveRow()`, `insertKey()`

### cluster: `avg-verwerkingsregister` (1 methods)

- `lib/Service/AvgComplianceService.php` — `findUnannotatedSchemasWithPii()`


## Bucket 2b — No capability owner (cluster + reverse-spec via `/opsx-reverse-spec --cluster`)

> ⚠️ Clusters whose label is a **namespace word** (`service`, `controller`, `event`, etc.) are component-type labels, NOT behavioral names. They REQUIRE a human pre-split before running `/opsx-reverse-spec --cluster`.

### cluster: `service` (1055 methods) ⚠️ namespace-word

- `lib/Service/ApplicationService.php` — `__construct()`, `findAll()`, `find()`, `create()`, `delete()`, `update()` + more
- `lib/Service/AvgComplianceService.php` — `__construct()`, `runAllChecks()`, `aggregatePiiBySchema()`, `registerHasAnnotation()`, `schemaHasAnnotation()`, `resolveSchemaTitle()`
- `lib/Service/BulkTranslationService.php` — `__construct()`, `loadSchema()`, `translateObject()`
- `lib/Service/Calculation/CalculationAnnotationValidator.php` — `validate()`, `walk()`, `findCycle()`, `walkDateDiff()`
- `lib/Service/Calculation/CalculationEvaluator.php` — `__construct()`, `concat()`, `ifExpr()`, `boolEval()`, `reduceBool()`, `arith()` + more
- `lib/Service/ConditionMatcher.php` — `__construct()`, `objectMatchesConditions()`, `unwrapResolvedRelation()`, `getObjectValue()`, `resolveDynamicValue()`
- _... 118 more files_

### cluster: `controller` (458 methods) ⚠️ namespace-word

- `lib/Controller/AgentsController.php` — `page()`
- `lib/Controller/ApplicationsController.php` — `__construct()`, `index()`, `show()`, `create()`, `patch()`, `destroy()` + more
- `lib/Controller/BulkController.php` — `resolveRegisterSchemaIds()`, `deleteSchema()`, `deleteSchemaObjects()`, `deleteRegister()`
- `lib/Controller/ConfigurationController.php` — `__construct()`, `index()`, `enrichDetails()`, `create()`, `destroy()`, `preview()` + more
- `lib/Controller/ConfigurationsController.php` — `__construct()`, `index()`, `create()`, `update()`, `patch()`, `destroy()` + more
- `lib/Controller/ConsumersController.php` — `index()`, `show()`, `create()`, `update()`, `destroy()`, `patch()` + more
- _... 50 more files_

### cluster: `modals` (71 methods)

- `src/modals/agent/DeleteAgent.vue` — `confirmDelete()`
- `src/modals/agent/EditAgent.vue` — `initializeAgent()`, `if()`
- `src/modals/application/DeleteApplication.vue` — `deleteApplication()`
- `src/modals/application/EditApplication.vue` — `fetchOrganisations()`
- `src/modals/configuration/DeleteConfiguration.vue` — `closeModal()`
- `src/modals/configuration/EditConfiguration.vue` — `updateTitle()`, `if()`
- _... 43 more files_

### cluster: `event` (64 methods) ⚠️ namespace-word

- `lib/Event/AgentCreatedEvent.php` — `__construct()`
- `lib/Event/AgentDeletedEvent.php` — `__construct()`
- `lib/Event/AgentUpdatedEvent.php` — `__construct()`
- `lib/Event/ApplicationCreatedEvent.php` — `__construct()`
- `lib/Event/ApplicationDeletedEvent.php` — `__construct()`
- `lib/Event/ApplicationUpdatedEvent.php` — `__construct()`
- _... 30 more files_

### cluster: `views` (45 methods)

- `src/views/Endpoint/EndpointDetails.vue` — `testEndpoint()`, `if()`
- `src/views/account/sections/AccountSection.vue` — `requestDeactivation()`
- `src/views/account/sections/AvatarSection.vue` — `triggerUpload()`
- `src/views/account/sections/PasswordSection.vue` — `changePassword()`
- `src/views/account/sections/TokensSection.vue` — `loadTokens()`
- `src/views/agents/AgentsIndex.vue` — `toggleSelectAll()`, `if()`
- _... 26 more files_

### cluster: `store` (27 methods)

- `src/store/modules/agent.js` — `useAgentStore()`
- `src/store/modules/application.js` — `useApplicationStore()`
- `src/store/modules/avg.js` — `RECHTSGROND_VOCABULARY()`, `STATUS_VOCABULARY()`, `useAvgStore()`
- `src/store/modules/configuration.js` — `useConfigurationStore()`
- `src/store/modules/conversation.ts` — `useConversationStore()`
- `src/store/modules/dashboard.js` — `setupDashboardStoreWatchers()`, `useDashboardStore()`
- _... 10 more files_

### cluster: `services` (22 methods)

- `src/services/AppInitializationService.js` — `initializeAppData()`, `reloadAppData()`, `loadRegisters()`, `forceLoadRegisters()`, `loadSchemas()`, `forceLoadSchemas()` + more
- `src/services/dateUtils.js` — `stringToDate()`, `dateToString()`
- `src/services/getTheme.js` — `getTheme()`

### cluster: `exception` (21 methods) ⚠️ namespace-word

- `lib/Exception/AppendOnlyException.php` — `getSchemaIdentifier()`, `getOperation()`, `__construct()`, `toResponseBody()`
- `lib/Exception/CircularReferenceException.php` — `getTargetSchemaSlug()`, `getCycle()`, `toArray()`, `getReferencedUuid()`
- `lib/Exception/HookStoppedException.php` — `getErrors()`, `__construct()`
- `lib/Exception/LockedException.php` — `__construct()`
- `lib/Exception/ProviderUnavailableException.php` — `getCause()`, `getDetails()`
- `lib/Exception/ReferenceValidationException.php` — `getReferencedUuid()`, `getTargetRegister()`, `toArray()`, `__construct()`, `getPropertyName()`, `getTargetSchemaSlug()`
- _... 2 more files_

### cluster: `entities` (18 methods)

- `src/entities/agent/agent.mock.ts` — `mockAgentData()`, `mockAgent()`
- `src/entities/application/application.mock.ts` — `mockApplicationData()`, `mockApplication()`
- `src/entities/conversation/conversation.mock.ts` — `mockConversationData()`, `mockConversation()`
- `src/entities/database/database.mock.ts` — `mockDatabaseData()`, `mockDatabase()`
- `src/entities/message/message.mock.ts` — `mockMessageData()`, `mockMessage()`
- `src/entities/mocks/configuration.js` — `mockConfiguration()`, `mockConfigurations()`
- _... 3 more files_

### cluster: `backgroundjob` (14 methods)

- `lib/BackgroundJob/CacheWarmupJob.php` — `run()`
- `lib/BackgroundJob/ExecutionHistoryCleanupJob.php` — `run()`
- `lib/BackgroundJob/NameCacheWarmupJob.php` — `run()`
- `lib/BackgroundJob/ReportRenderJob.php` — `__construct()`, `run()`, `shouldRender()`, `renderAndDeliver()`, `writeToFiles()`, `loadReportsRegister()` + more
- `lib/BackgroundJob/SolrWarmupJob.php` — `run()`, `calculateObjectsPerSecond()`, `isSolrAvailable()`

### cluster: `listener` (14 methods) ⚠️ namespace-word

- `lib/Listener/CalculationOnSaveListener.php` — `__construct()`, `handle()`, `process()`, `serialise()`, `loadSchema()`, `getCalculations()`
- `lib/Listener/NotifyPushListener.php` — `resetStaticState()`, `resolveQueue()`, `resolveRegisterSlug()`, `resolveSchemaSlug()`
- `lib/Listener/RealtimeEventListener.php` — `__construct()`, `handle()`
- `lib/Listener/TranslationProjectionListener.php` — `__construct()`, `handle()`

### cluster: `command` (11 methods) ⚠️ namespace-word

- `lib/Command/BackfillSystemOwnerCommand.php` — `__construct()`, `configure()`, `resolveRegisters()`, `resolveSchemas()`, `execute()`, `backfillTable()`
- `lib/Command/RematerialiseCalculationsCommand.php` — `__construct()`, `configure()`, `execute()`, `withSelf()`, `getCalculations()`

### cluster: `components` (10 methods)

- `src/components/AgentSelector.vue` — `t()`
- `src/components/PaginationComponent.vue` — `changePage()`, `if()`
- `src/components/cards/ConfigurationCard.vue` — `checkIfImported()`
- `src/components/i18n/BulkTranslateDialog.vue` — `onSubmit()`
- `src/components/i18n/TranslationCompletenessBadge.vue` — `ratioPercent()`
- `src/components/i18n/TranslationFieldEditor.vue` — `getValue()`
- _... 2 more files_

### cluster: `settings` (7 methods)

- `lib/Settings/IntegrationsAdminSettings.php` — `getForm()`, `getSection()`, `getPriority()`, `buildRows()`, `describe()`, `probeHealth()` + more

### cluster: `repair` (6 methods) ⚠️ namespace-word

- `lib/Repair/LogDanglingLinkedTypes.php` — `getName()`, `run()`, `loadSchemas()`, `scan()`, `extractLinkedTypes()`, `safeStringAccessor()`

### cluster: `capabilities` (5 methods)

- `lib/Capabilities/IntegrationsCapability.php` — `currentUserIsAdmin()`, `getCapabilities()`, `describe()`
- `lib/Capabilities/UrnCapability.php` — `__construct()`, `getCapabilities()`

### cluster: `dto` (3 methods)

- `lib/Dto/DeletionAnalysis.php` — `__construct()`, `empty()`, `toArray()`

### cluster: `navigation` (2 methods)

- `src/navigation/Configuration.vue` — `fetchData()`
- `src/navigation/MainMenu.vue` — `handleNavigate()`

### cluster: `tool` (2 methods) ⚠️ namespace-word

- `lib/Tool/AgentTool.php` — `__construct()`
- `lib/Tool/StreamingToolInstanceWrapper.php` — `detectIsError()`

### cluster: `dialogs` (1 methods)

- `src/dialogs/Dialogs.vue` — `onConfigSetCreated()`

### cluster: `router` (1 methods)

- `src/router/index.js` — `routeKeyByPath()`

### cluster: `middleware` (1 methods) ⚠️ namespace-word

- `lib/Middleware/TenantQuotaMiddleware.php` — `afterException()`


## Bucket 3 — Surfaced for human triage

### 3a — Possibly broken (implementation evidence in git history)

- **approval-workflow#REQ-003** — REQ-003: List and filter approval steps
  - removed-lines cache matched keywords: pending, initialize, combination, only, json
- **archival-destruction-workflow#REQ-005** — REQ-005: Destruction Certificate Generation
  - removed-lines cache matched keywords: were, 1995, archivist, full, after
- **seed-related-items#REQ-03** — REQ-03: Process Related Items After Object Creation
  - removed-lines cache matched keywords: related, note, after, entries, task
- **seed-related-items#REQ-04** — REQ-04: Note Seeding
  - removed-lines cache matched keywords: related, note, title, entries, task
- **seed-related-items#REQ-10** — REQ-10: Logging
  - removed-lines cache matched keywords: related, warning, info, total, summary
- **geo-metadata-kaart#GEO-003** — Requirement: REQ-GEO-003 -- Map visualization component with PDOK tile layers
  - removed-lines cache matched keywords: using, appropriate, tile, levels, follow
- **geo-metadata-kaart#GEO-010** — Requirement: REQ-GEO-010 -- Geo-fencing with event triggers
  - removed-lines cache matched keywords: schemas, configured, geographic, falls, json
- **deprecate-published-metadata#REQ-2** — REQ-2: Frontend Copy Modal Cleanup
  - removed-lines cache matched keywords: trait, exist, views, longer, stats
- **deprecate-published-metadata#REQ-4** — REQ-4: Import UI Cleanup
  - removed-lines cache matched keywords: trait, using, logged, bypass, deprecation
- **deprecate-published-metadata#REQ-5** — REQ-5: MultiTenancyTrait Documentation
  - removed-lines cache matched keywords: trait, using, logged, bypass, deprecation
- **deprecate-published-metadata#REQ-6** — REQ-6: Deprecation Warnings
  - removed-lines cache matched keywords: using, published, auto, warning, config
- **extended-field-types#EFT-003** — Requirement: REQ-EFT-003 — The `recurrence` type SHALL store an RFC 5545 RRULE string and emit upcoming occurrences on read
  - removed-lines cache matched keywords: sabre, persist, store, pattern, configurable
- **extended-field-types#EFT-005** — Requirement: REQ-EFT-005 — The `color` type SHALL accept hex / rgba / oklch notations and validate per declared format
  - removed-lines cache matched keywords: appropriate, requires, forms, format, alpha
- **oas-validation#API-46** — Scenario: Error responses include problem details (API-46 / RFC 7807)
  - removed-lines cache matched keywords: exist, enhancement, schemas, related, processes
- **openregister-app-manifest#MAN-002** — Requirement: REQ-OR-MAN-002 Manifest declares zero Conduction-app dependencies
  - removed-lines cache matched keywords: repo, rendering, guidance, only, skips
- **openregister-app-manifest#MAN-007** — Requirement: REQ-OR-MAN-007 Build gate validates the manifest
  - removed-lines cache matched keywords: library, validates, against, invalid, json
- **openregister-app-manifest#MAN-008** — Requirement: REQ-OR-MAN-008 Manifest version reflects the adoption tier
  - removed-lines cache matched keywords: consumers, only, manifest, content, follow

### 3b — Never implemented

_(none — every live REQ has at least one keyword match in git history; this can be a false-negative when REQ vocabulary is generic. Inspect the REQs personally if you expect specific unimplemented work.)_

## Bucket 4 — ADR conformance findings

### missing-spdx-in-file-docblock (633 findings)

- `lib/Activity/Filter.php`
- `lib/Activity/Provider.php`
- `lib/Activity/ProviderSubjectHandler.php`
- `lib/Activity/Setting/ObjectSetting.php`
- `lib/Activity/Setting/RegisterSetting.php`
- `lib/Activity/Setting/SchemaSetting.php`
- `lib/AppInfo/Application.php`
- `lib/BackgroundJob/ActionRetryJob.php`
- `lib/BackgroundJob/ActionScheduleJob.php`
- `lib/BackgroundJob/NotificationQueueFlushJob.php`
- `lib/BackgroundJob/BlobMigrationJob.php`
- `lib/BackgroundJob/BulkLegalHoldJob.php`
- `lib/BackgroundJob/CacheWarmupJob.php`
- `lib/BackgroundJob/CronFileTextExtractionJob.php`
- `lib/BackgroundJob/DestructionCheckJob.php`
- `lib/BackgroundJob/DestructionExecutionJob.php`
- `lib/BackgroundJob/ExecutionHistoryCleanupJob.php`
- `lib/BackgroundJob/FileTextExtractionJob.php`
- `lib/BackgroundJob/HookRetryJob.php`
- `lib/BackgroundJob/NameCacheWarmupJob.php`
- `lib/BackgroundJob/ObjectTextExtractionJob.php`
- `lib/BackgroundJob/ScheduledNotificationJob.php`
- `lib/BackgroundJob/ScheduledWorkflowJob.php`
- `lib/BackgroundJob/SolrNightlyWarmupJob.php`
- `lib/BackgroundJob/SolrWarmupJob.php`
- `lib/BackgroundJob/TenantDeprovisionJob.php`
- `lib/BackgroundJob/TenantPurgeJob.php`
- `lib/BackgroundJob/TenantUsageSyncJob.php`
- `lib/BackgroundJob/TransferExecutionJob.php`
- `lib/BackgroundJob/WebhookDeliveryJob.php`
- _... 603 more_

### forbidden-patterns (0 findings)

_(no findings)_

### direct-sql (16 findings)

- `lib/Controller/SettingsController.php`
- `lib/Db/MagicMapper/MagicBulkHandler.php`
- `lib/Db/MagicMapper/MagicFacetHandler.php`
- `lib/Db/MagicMapper/MagicSearchHandler.php`
- `lib/Db/MagicMapper/MagicStatisticsHandler.php`
- `lib/Db/MagicMapper/MagicTableHandler.php`
- `lib/Db/MagicMapper.php`
- `lib/Db/OrganisationMapper.php`
- `lib/Service/Aggregation/AggregationRunner.php`
- `lib/Service/EmailService.php`
- `lib/Service/Object/CacheHandler.php`
- `lib/Service/Object/LinkedEntityEnricher.php`
- `lib/Service/Object/ReferentialIntegrityService.php`
- `lib/Service/RegisterService.php`
- `lib/Service/Schemas/SchemaCacheHandler.php`
- `lib/Service/SettingsService.php`


## Scanner notes

- Scanner: deterministic Python scorer following SKILL.md Pass A signal-extraction.
- Bucket 1 confidence: 0.70-0.85 → NEEDS-REVIEW flagged; 0.85+ → trusted.
- Reverse pass uses 60s git-log timeout (skill default is 30s).
- Archive REQs excluded from live matching (treated as superseded by their live counterparts).

## How this scan was built

This worktree (`chore/spec-collect-2026-05-24`) was branched from `origin/development` and then **layered with spec.md files from 14 unmerged feature branches** via `git checkout <branch> -- <path>` (path-add only, no code merges — see `git log -- openspec/specs openspec/changes` for the 14 spec-collect commits). The scan therefore compares **latest development code** against **the union of every active spec in flight**.

Branches that contributed unique spec.md files:

- `feat/1435/anonymisation-specs` (+50 spec.md files)
- `feature/1554/notificatie-engine` (+1 spec.md files)
- `feature/linked-entity-types` (+7 spec.md files)
- `feat/nextcloud-integrations` (+1 spec.md files)
- `feature/php-linting-specs` (+2 spec.md files)
- `fix/trigger-ci` (+1 spec.md files)
- `feat/specter-fleet-rollout` (+5 spec.md files)
- `feature/workflow-in-import` (+2 spec.md files)
- `test/anonimiseren-bij-de-bron-or` (+1 spec.md files)
- `feat/text-extraction-office-completeness` (+1 spec.md files)
- `feat/pdf-anonymisation` (+1 spec.md files)
- `feat/office-document-sanitization` (+1 spec.md files)
- `feat/manual-entity-anonymisation` (+1 spec.md files)
- `feat/1497/anonymiser-backend-selection` (+1 spec.md files)
