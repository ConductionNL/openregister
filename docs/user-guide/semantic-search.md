# Semantic Search User Guide

## Overview

Semantic search allows you to find information by meaning, not just by matching keywords. Instead of searching for exact words, you can search for concepts, and the system will find related content even if it uses different terminology.

## What is Semantic Search?

Traditional search (keyword search) looks for exact word matches:
- Search for "car" → Only finds documents containing "car"
- Misses documents about "automobile", "vehicle", "transportation"

Semantic search understands meaning:
- Search for "car" → Finds "car", "automobile", "vehicle", "transportation"
- Understands that "Amsterdam" relates to "Netherlands" and "Dutch"
- Recognizes that "budget" is related to "financial planning" and "costs"

## How It Works

1. **Vectorization**: Your files and objects are converted into mathematical representations (vectors) that capture their meaning
2. **Query Understanding**: When you search, your query is also converted to a vector
3. **Similarity Matching**: The system finds content with vectors similar to your query vector
4. **Ranking**: Results are ranked by how closely they match your query's meaning

## Search Modes

OpenRegister offers three search modes:

### Hybrid Search (Recommended)
Combines keyword and semantic search for the best results.

**Use when**: You want the most accurate results (default mode)

**Example**: 
- Query: "project management tools"
- Finds: Documents with exact phrase + documents about "planning software", "task coordination", "workflow systems"

### Semantic Search
Pure meaning-based search using vector similarity.

**Use when**: You want to find conceptually similar content, even with different wording

**Example**:
- Query: "reducing costs"
- Finds: Documents about "budget optimization", "financial efficiency", "expense management"

### Keyword Search
Traditional full-text search using SOLR.

**Use when**: You need exact phrase matching or very fast results

**Example**:
- Query: "invoice #12345"
- Finds: Only documents containing that exact invoice number

## Using Search

### From the Chat Interface

The easiest way to use semantic search is through the AI Chat:

1. Navigate to **AI Chat** from the main menu
2. Type your question in natural language
3. The system automatically:
   - Uses hybrid search to find relevant context
   - Generates an answer based on your data
   - Cites sources with similarity scores

**Example questions**:
- "What projects are related to Amsterdam?"
- "Show me files that mention budgets over 1 million"
- "Find information about customer complaints"
- "What do we have about sustainability initiatives?"

### From the Search API

For programmatic access, use the API endpoints:

```bash
# Semantic search
GET /apps/openregister/api/solr/search/semantic?query=your+query&limit=10

# Hybrid search
GET /apps/openregister/api/solr/search/hybrid?query=your+query&limit=10
```

## Understanding Results

### Similarity Scores
Results include a similarity score from 0.0 to 1.0:
- **0.9-1.0**: Extremely similar (almost identical meaning)
- **0.8-0.9**: Very similar (closely related concepts)
- **0.7-0.8**: Similar (related but distinct concepts)
- **0.6-0.7**: Somewhat similar (loosely related)
- **Below 0.6**: May not be relevant

### Source Citations
When using the chat interface, each answer includes:
- **Source name**: File name or object title
- **Source type**: File or Object
- **Similarity score**: How closely it matches your query
- **Text excerpt**: Relevant content from the source

Click on a source to view the full document or object.

## Best Practices

### Writing Effective Queries

✅ **Do**:
- Use natural language: "What are our sustainability goals?"
- Be specific about what you're looking for
- Include key concepts: "budget planning 2025"
- Ask questions: "How do we handle customer refunds?"

❌ **Don't**:
- Use single words without context: "budget"
- Overload with technical operators: "budget AND (2024 OR 2025) NOT draft"
- Expect exact phrase matching in semantic mode

### Improving Results

If you're not getting good results:

1. **Try Different Phrasing**:
   - Instead of "cars", try "vehicles" or "transportation"
   - Rephrase your query to emphasize different aspects

2. **Use Hybrid Mode**:
   - Combines benefits of both keyword and semantic search
   - Usually gives the most accurate results

3. **Provide Feedback**:
   - Use thumbs up/down in chat to help improve results
   - The system learns from your feedback

4. **Check Vectorization**:
   - Go to Settings → Object Management / File Management
   - Ensure relevant content has been vectorized
   - Check stats to see vectorization progress

## Configuration

### For Users

**Adjust Search Preferences**:
1. Open AI Chat
2. Click **Settings** button
3. Configure:
   - **Search Mode**: Hybrid / Semantic / Keyword
   - **Number of Sources**: How many documents to retrieve (1-10)
   - **Search in files**: Include/exclude file content
   - **Search in objects**: Include/exclude structured data

### For Administrators

**Enable Semantic Search**:
1. Go to **Settings → Administration → OpenRegister**
2. Configure **LLM Settings**:
   - Choose embedding provider (OpenAI or Ollama)
   - Enter API key (if using OpenAI)
   - Select embedding model

3. **Vectorize Content**:
   - Go to **Object Management**:
     - Enable vectorization
     - Select schemas to vectorize
     - Click "Start Bulk Vectorization"
   - Go to **File Management**:
     - Enable vectorization
     - Configure chunking strategy
     - Enable file types to process

4. **Monitor Progress**:
   - Check stats in Object/File Management dialogs
   - View vectorization progress
   - Monitor vector database size

## Common Use Cases

### 1. Finding Related Projects
**Query**: "Show me projects related to renewable energy"

**Results**: Projects mentioning:
- Solar power, wind energy, clean energy
- Sustainability initiatives
- Carbon reduction programs
- Green infrastructure

### 2. Budget Analysis
**Query**: "What files discuss budget increases?"

**Results**: Files containing:
- Financial growth, increased funding
- Cost escalation, expanded budget
- Resource allocation changes
- Funding enhancements

### 3. Customer Feedback
**Query**: "Find complaints about delivery times"

**Results**: Documents about:
- Shipping delays, late arrivals
- Delivery issues, transport problems
- Customer dissatisfaction with speed
- Logistics challenges

### 4. Policy Research
**Query**: "What are our data privacy policies?"

**Results**: Documents covering:
- GDPR compliance, data protection
- Privacy guidelines, security measures
- Information handling procedures
- Confidentiality agreements

## Troubleshooting

### "No results found"

**Possible causes**:
- Content hasn't been vectorized yet
- Query is too specific or uses uncommon terminology
- File types aren't enabled for processing

**Solutions**:
- Check vectorization status in Object/File Management
- Try rephrasing your query
- Use hybrid mode for broader coverage
- Ensure relevant file types are enabled

### "Results seem irrelevant"

**Possible causes**:
- Insufficient vectorized content
- Query is too broad or ambiguous
- Similarity threshold may be too low

**Solutions**:
- Vectorize more content
- Make your query more specific
- Use keyword search for exact matches
- Provide feedback (thumbs down) to help improve

### "Search is slow"

**Possible causes**:
- Large vector database
- High number of sources requested
- OpenAI API latency

**Solutions**:
- Reduce number of sources in settings
- Use keyword mode for faster results
- Consider using local Ollama for embeddings
- Contact administrator about database optimization

## Privacy & Security

### Your Data
- All vectorization happens on your server
- Vectors are stored in your database
- File content never leaves your infrastructure (except for embeddings)

### API Keys
- OpenAI API keys are stored in configuration
- Only used for generating embeddings and chat responses
- Administrator can switch to local Ollama for complete privacy

### Search Privacy
- Search queries are processed locally
- Chat history is stored per-user
- Conversation history is private to each user
- Admins cannot see your chat conversations

## Tips for Power Users

### Combining Search Techniques
Use hybrid search with specific terms:
- "sustainable development Amsterdam" finds both exact mentions of Amsterdam AND conceptually related sustainability content

### Understanding Vector Dimensions
- Larger models (e.g., text-embedding-3-large, 3072 dimensions) → More nuanced understanding
- Smaller models (e.g., text-embedding-3-small, 1536 dimensions) → Faster, less storage

### Optimizing for Your Domain
- Vectorize domain-specific documents first
- Use consistent terminology in your organization
- Provide feedback to improve relevance

### Monitoring Costs
- Each OpenAI embedding API call has a small cost
- Bulk vectorization is more cost-effective than individual
- Consider Ollama for free, local embeddings (slower but private)

## FAQs

**Q: How is semantic search different from regular search?**  
A: Regular search matches words. Semantic search understands meaning and finds conceptually similar content.

**Q: Do I need to change how I search?**  
A: No! Just use natural language. The system handles the complexity.

**Q: Can I still use exact phrase matching?**  
A: Yes! Use keyword mode or hybrid mode (which includes exact matching).

**Q: How long does vectorization take?**  
A: Depends on content volume. Typically 100-500 items per minute.

**Q: Does this work with all file types?**  
A: We support 15+ formats including PDF, Word, Excel, images (with OCR), and more.

**Q: Is my data secure?**  
A: Yes! Only embeddings (mathematical representations) are sent to OpenAI, not full content. Use Ollama for complete on-premises processing.

**Q: Can I search across different languages?**  
A: Yes! Multilingual embedding models understand multiple languages.

**Q: How do I know if results are accurate?**  
A: Check similarity scores and source citations. Use feedback buttons to improve results.

## Getting Help

- **Documentation**: See `/docs/` for technical details
- **Administrator**: Contact your OpenRegister administrator
- **Feedback**: Use thumbs up/down in chat to report issues
- **Support**: Visit [Conduction](https://www.conduction.nl) for enterprise support

---

*Last updated: October 13, 2025*

