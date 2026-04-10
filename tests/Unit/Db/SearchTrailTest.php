<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\SearchTrail;
use PHPUnit\Framework\TestCase;

class SearchTrailTest extends TestCase
{
    private SearchTrail $searchTrail;

    protected function setUp(): void
    {
        $this->searchTrail = new SearchTrail();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->searchTrail->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['searchTerm']);
        $this->assertSame('json', $fieldTypes['queryParameters']);
        $this->assertSame('integer', $fieldTypes['resultCount']);
        $this->assertSame('integer', $fieldTypes['totalResults']);
        $this->assertSame('integer', $fieldTypes['register']);
        $this->assertSame('integer', $fieldTypes['schema']);
        $this->assertSame('string', $fieldTypes['registerUuid']);
        $this->assertSame('string', $fieldTypes['schemaUuid']);
        $this->assertSame('string', $fieldTypes['user']);
        $this->assertSame('string', $fieldTypes['userName']);
        $this->assertSame('string', $fieldTypes['registerName']);
        $this->assertSame('string', $fieldTypes['schemaName']);
        $this->assertSame('string', $fieldTypes['session']);
        $this->assertSame('string', $fieldTypes['ipAddress']);
        $this->assertSame('string', $fieldTypes['userAgent']);
        $this->assertSame('string', $fieldTypes['requestUri']);
        $this->assertSame('string', $fieldTypes['httpMethod']);
        $this->assertSame('integer', $fieldTypes['responseTime']);
        $this->assertSame('integer', $fieldTypes['page']);
        $this->assertSame('integer', $fieldTypes['limit']);
        $this->assertSame('integer', $fieldTypes['offset']);
        $this->assertSame('boolean', $fieldTypes['facetsRequested']);
        $this->assertSame('boolean', $fieldTypes['facetableRequested']);
        $this->assertSame('json', $fieldTypes['filters']);
        $this->assertSame('json', $fieldTypes['sortParameters']);
        $this->assertSame('boolean', $fieldTypes['publishedOnly']);
        $this->assertSame('string', $fieldTypes['executionType']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('string', $fieldTypes['organisationId']);
        $this->assertSame('string', $fieldTypes['organisationIdType']);
        $this->assertSame('datetime', $fieldTypes['expires']);
        $this->assertSame('integer', $fieldTypes['size']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->searchTrail->getUuid());
        $this->assertNull($this->searchTrail->getSearchTerm());
        $this->assertNull($this->searchTrail->getResultCount());
        $this->assertNull($this->searchTrail->getTotalResults());
        $this->assertNull($this->searchTrail->getRegister());
        $this->assertNull($this->searchTrail->getSchema());
        $this->assertNull($this->searchTrail->getRegisterUuid());
        $this->assertNull($this->searchTrail->getSchemaUuid());
        $this->assertNull($this->searchTrail->getUser());
        $this->assertNull($this->searchTrail->getUserName());
        $this->assertNull($this->searchTrail->getSession());
        $this->assertNull($this->searchTrail->getIpAddress());
        $this->assertNull($this->searchTrail->getUserAgent());
        $this->assertNull($this->searchTrail->getRequestUri());
        $this->assertNull($this->searchTrail->getHttpMethod());
        $this->assertNull($this->searchTrail->getResponseTime());
        $this->assertNull($this->searchTrail->getPage());
        $this->assertNull($this->searchTrail->getCreated());
        $this->assertNull($this->searchTrail->getExecutionType());
    }

    // --- String field getters/setters ---

    public function testSetAndGetUuid(): void
    {
        $this->searchTrail->setUuid('trail-uuid-123');
        $this->assertSame('trail-uuid-123', $this->searchTrail->getUuid());
    }

    public function testSetAndGetSearchTerm(): void
    {
        $this->searchTrail->setSearchTerm('test query');
        $this->assertSame('test query', $this->searchTrail->getSearchTerm());
    }

    public function testSetAndGetRegisterUuid(): void
    {
        $this->searchTrail->setRegisterUuid('reg-uuid');
        $this->assertSame('reg-uuid', $this->searchTrail->getRegisterUuid());
    }

    public function testSetAndGetSchemaUuid(): void
    {
        $this->searchTrail->setSchemaUuid('schema-uuid');
        $this->assertSame('schema-uuid', $this->searchTrail->getSchemaUuid());
    }

    public function testSetAndGetUser(): void
    {
        $this->searchTrail->setUser('admin');
        $this->assertSame('admin', $this->searchTrail->getUser());
    }

    public function testSetAndGetUserName(): void
    {
        $this->searchTrail->setUserName('Admin User');
        $this->assertSame('Admin User', $this->searchTrail->getUserName());
    }

    public function testSetAndGetSession(): void
    {
        $this->searchTrail->setSession('sess-abc');
        $this->assertSame('sess-abc', $this->searchTrail->getSession());
    }

    public function testSetAndGetIpAddress(): void
    {
        $this->searchTrail->setIpAddress('192.168.1.1');
        $this->assertSame('192.168.1.1', $this->searchTrail->getIpAddress());
    }

    public function testSetAndGetUserAgent(): void
    {
        $this->searchTrail->setUserAgent('Mozilla/5.0');
        $this->assertSame('Mozilla/5.0', $this->searchTrail->getUserAgent());
    }

    public function testSetAndGetRequestUri(): void
    {
        $this->searchTrail->setRequestUri('/api/objects');
        $this->assertSame('/api/objects', $this->searchTrail->getRequestUri());
    }

    public function testSetAndGetHttpMethod(): void
    {
        $this->searchTrail->setHttpMethod('GET');
        $this->assertSame('GET', $this->searchTrail->getHttpMethod());
    }

    public function testSetAndGetExecutionType(): void
    {
        $this->searchTrail->setExecutionType('async');
        $this->assertSame('async', $this->searchTrail->getExecutionType());
    }

    // --- Integer field getters/setters ---

    public function testSetAndGetResultCount(): void
    {
        $this->searchTrail->setResultCount(25);
        $this->assertSame(25, $this->searchTrail->getResultCount());
    }

    public function testSetAndGetTotalResults(): void
    {
        $this->searchTrail->setTotalResults(100);
        $this->assertSame(100, $this->searchTrail->getTotalResults());
    }

    public function testSetAndGetRegister(): void
    {
        $this->searchTrail->setRegister(1);
        $this->assertSame(1, $this->searchTrail->getRegister());
    }

    public function testSetAndGetSchema(): void
    {
        $this->searchTrail->setSchema(2);
        $this->assertSame(2, $this->searchTrail->getSchema());
    }

    public function testSetAndGetResponseTime(): void
    {
        $this->searchTrail->setResponseTime(150);
        $this->assertSame(150, $this->searchTrail->getResponseTime());
    }

    public function testSetAndGetPage(): void
    {
        $this->searchTrail->setPage(3);
        $this->assertSame(3, $this->searchTrail->getPage());
    }

    // --- JSON fields with custom getters returning empty array ---

    public function testGetQueryParametersReturnsEmptyArrayWhenNull(): void
    {
        $this->assertSame([], $this->searchTrail->getQueryParameters());
    }

    public function testSetAndGetQueryParameters(): void
    {
        $params = ['_search' => 'test', 'page' => 1];
        $this->searchTrail->setQueryParameters($params);
        $this->assertSame($params, $this->searchTrail->getQueryParameters());
    }

    public function testGetFiltersReturnsEmptyArrayWhenNull(): void
    {
        $this->assertSame([], $this->searchTrail->getFilters());
    }

    public function testSetAndGetFilters(): void
    {
        $filters = ['status' => 'active'];
        $this->searchTrail->setFilters($filters);
        $this->assertSame($filters, $this->searchTrail->getFilters());
    }

    public function testGetSortParametersReturnsEmptyArrayWhenNull(): void
    {
        $this->assertSame([], $this->searchTrail->getSortParameters());
    }

    public function testSetAndGetSortParameters(): void
    {
        $sort = ['field' => 'name', 'direction' => 'asc'];
        $this->searchTrail->setSortParameters($sort);
        $this->assertSame($sort, $this->searchTrail->getSortParameters());
    }

    // --- DateTime fields ---

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->searchTrail->setCreated($dt);
        $this->assertSame($dt, $this->searchTrail->getCreated());
    }

    // --- Custom setters (registerName, schemaName) ---

    public function testSetAndGetRegisterName(): void
    {
        $this->searchTrail->setRegisterName('My Register');
        $this->assertSame('My Register', $this->searchTrail->getRegisterName());
    }

    public function testSetAndGetSchemaName(): void
    {
        $this->searchTrail->setSchemaName('My Schema');
        $this->assertSame('My Schema', $this->searchTrail->getSchemaName());
    }

    // --- getJsonFields ---

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->searchTrail->getJsonFields();

        $this->assertContains('queryParameters', $jsonFields);
        $this->assertContains('filters', $jsonFields);
        $this->assertContains('sortParameters', $jsonFields);
        $this->assertNotContains('uuid', $jsonFields);
        $this->assertNotContains('searchTerm', $jsonFields);
    }

    // --- hydrate ---

    public function testHydrateSetsFields(): void
    {
        $this->searchTrail->hydrate([
            'uuid'       => 'hydrated-uuid',
            'searchTerm' => 'hydrated search',
            'register'   => 5,
        ]);

        $this->assertSame('hydrated-uuid', $this->searchTrail->getUuid());
        $this->assertSame('hydrated search', $this->searchTrail->getSearchTerm());
        $this->assertSame(5, $this->searchTrail->getRegister());
    }

    public function testHydrateConvertsEmptyArrayJsonFieldsToNull(): void
    {
        $this->searchTrail->hydrate([
            'queryParameters' => [],
            'filters'         => [],
            'sortParameters'  => [],
        ]);

        // getQueryParameters returns [] via custom getter even when internal is null
        $this->assertSame([], $this->searchTrail->getQueryParameters());
        $this->assertSame([], $this->searchTrail->getFilters());
        $this->assertSame([], $this->searchTrail->getSortParameters());
    }

    public function testHydrateReturnsThis(): void
    {
        $result = $this->searchTrail->hydrate(['uuid' => 'test']);
        $this->assertSame($this->searchTrail, $result);
    }

    public function testHydrateIgnoresUnknownFields(): void
    {
        // Should not throw
        $this->searchTrail->hydrate(['nonExistentField' => 'value']);
        $this->assertNull($this->searchTrail->getUuid());
    }

    // --- __toString ---

    public function testToStringReturnsUuidWhenSet(): void
    {
        $this->searchTrail->setUuid('trail-uuid');
        $this->assertSame('trail-uuid', (string)$this->searchTrail);
    }

    public function testToStringReturnsSearchTermWhenNoUuid(): void
    {
        $this->searchTrail->setSearchTerm('my search');
        $this->assertSame('Search: my search', (string)$this->searchTrail);
    }

    public function testToStringFallsBackToFinalDefault(): void
    {
        $this->assertSame('Search Trail', (string)$this->searchTrail);
    }

    // --- jsonSerialize ---

    public function testJsonSerializeAllFieldsPresent(): void
    {
        $json = $this->searchTrail->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'searchTerm', 'queryParameters', 'resultCount',
            'totalResults', 'register', 'schema', 'registerUuid', 'schemaUuid',
            'user', 'userName', 'registerName', 'schemaName', 'session',
            'ipAddress', 'userAgent', 'requestUri', 'httpMethod', 'responseTime',
            'page', 'limit', 'offset', 'facetsRequested', 'facetableRequested',
            'filters', 'sortParameters', 'publishedOnly', 'executionType',
            'created', 'organisationId', 'organisationIdType', 'expires', 'size',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }

    public function testJsonSerializeFormatsDatetimes(): void
    {
        $created = new DateTime('2024-01-01 10:00:00');
        $expires = new DateTime('2025-01-01 10:00:00');

        $this->searchTrail->setCreated($created);
        $this->searchTrail->setExpires($expires);

        $json = $this->searchTrail->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($expires->format('c'), $json['expires']);
    }

    public function testJsonSerializeDatetimesNullWhenNotSet(): void
    {
        $json = $this->searchTrail->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['expires']);
    }

    public function testJsonSerializeWithValues(): void
    {
        $this->searchTrail->setUuid('test-uuid');
        $this->searchTrail->setSearchTerm('my query');
        $this->searchTrail->setResultCount(10);
        $this->searchTrail->setRegister(1);
        $this->searchTrail->setSchema(2);
        $this->searchTrail->setHttpMethod('GET');

        $json = $this->searchTrail->jsonSerialize();

        $this->assertSame('test-uuid', $json['uuid']);
        $this->assertSame('my query', $json['searchTerm']);
        $this->assertSame(10, $json['resultCount']);
        $this->assertSame(1, $json['register']);
        $this->assertSame(2, $json['schema']);
        $this->assertSame('GET', $json['httpMethod']);
    }
}
