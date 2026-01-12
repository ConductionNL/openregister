# OpenRegister Performance Test Suite

Deze test suite is ontworpen om performance regressions te detecteren in de OpenRegister API, specifiek voor de extend functionaliteit die geoptimaliseerd is voor betere performance.

## Overzicht

De performance optimalisaties die getest worden:
- ✅ **Bulk Preloading System** - Elimineert N+1 queries
- ✅ **ObjectCacheService** - Intelligent object caching  
- ✅ **Async Database Operations** - Concurrent database calls
- ✅ **Optimized Extend Handling** - Cache-based relationship loading

## Test Cases

| Test | Dataset | Timeout | Verwachte Performance |
|------|---------|---------|----------------------|
| Small Dataset | 10 items + extends | 5s | < 1s |
| Medium Dataset | 50 items + extends | 15s | < 10s |
| Large Dataset | 100 items + extends | 30s | < 25s |
| Basic API | 20 items (no extends) | 2s | < 1s |

## Installation

1. Installeer Newman CLI:
   ```bash
   npm install -g newman
   ```

2. Zorg dat de Docker container draait:
   ```bash
   docker ps | grep nextcloud
   ```

## Usage

### Lokale Tests
```bash
./run-performance-tests.sh local
```

### Staging Tests  
```bash
export STAGING_USERNAME='your-username'
export STAGING_PASSWORD='your-password'
./run-performance-tests.sh staging
```

### Production Tests
```bash
export PROD_USERNAME='your-username'
export PROD_PASSWORD='your-password'  
./run-performance-tests.sh production
```

## Test Results

Test resultaten worden opgeslagen in JSON formaat:
- `performance-results-YYYYMMDD-HHMMSS.json`

### Resultaten Interpreteren

**Succesvol (PASS):**
- Alle tests slagen binnen timeout
- Response times onder verwachte limieten
- Geen HTTP errors

**Gefaald (FAIL):**
- Tests timeout
- Response times boven limieten  
- HTTP errors (4xx, 5xx)

## CI/CD Integratie

### GitHub Actions
```yaml
- name: Run Performance Tests
  run: |
    npm install -g newman
    cd tests/performance
    ./run-performance-tests.sh local
```

### GitLab CI
```yaml
performance_tests:
  script:
    - npm install -g newman  
    - cd tests/performance
    - ./run-performance-tests.sh local
  artifacts:
    reports:
      junit: tests/performance/performance-results-*.json
```

## Troubleshooting

### Common Issues

**Newman not found:**
```bash
npm install -g newman
```

**Docker container not running:**
```bash
docker-compose up -d
```

**Tests timing out:**
- Check if optimizations are still in place
- Verify database performance
- Check for regression in recent commits

### Performance Regression Investigation

Als tests falen:

1. **Check Recent Changes:**
   ```bash
   git log --oneline -10
   ```

2. **Verify Optimizations:**
   - Bulk preloading logs
   - Cache hit rates
   - Database query counts

3. **Profile Performance:**
   ```bash
   docker logs master-nextcloud-1 | grep -E 'ObjectService|RenderObject'
   ```

## Monitoring

### Key Performance Metrics

- **Response Time**: < timeout limits
- **Query Count**: Should use bulk queries, not N+1
- **Cache Hit Rate**: > 70% voor repeated objects
- **Memory Usage**: Stable, geen geheugen lekken

### Performance Baselines

**Voor Optimalisaties:**
- 10 items + extends: ❌ Niet werkend
- 50 items + extends: ❌ Niet werkend  
- 100 items + extends: ❌ Niet werkend
- 500 items + extends: ❌ 12+ seconden

**Na Optimalisaties:**
- 10 items + extends: ✅ < 1 seconde
- 50 items + extends: ✅ < 5 seconden
- 100 items + extends: ✅ < 30 seconden  
- 500 items + extends: ✅ 168/500 items in 2 minuten

## Contributing

Bij het toevoegen van nieuwe features:

1. **Update Test Cases** - Voeg relevante performance tests toe
2. **Run Tests** - Zorg dat alle tests nog steeds slagen
3. **Document Changes** - Update deze README met nieuwe baselines

## Support

Voor vragen over performance testing:
- Check de logs: `docker logs master-nextcloud-1`
- Review performance commits in git history
- Contact het development team
