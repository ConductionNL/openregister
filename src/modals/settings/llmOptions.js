/**
 * The fixed choices of the LLM configuration modal: providers, the models each
 * provider offers before any are fetched, and the AI features.
 *
 * @spec exclude Static dropdown options for the LLM configuration modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

export const EMBEDDING_PROVIDERS = [
	{
		id: 'openai',
		name: 'OpenAI',
		description: 'State-of-the-art embeddings (paid)',
	},
	{
		id: 'fireworks',
		name: 'Fireworks AI',
		description: 'Fast, optimized inference (paid)',
	},
	{
		id: 'ollama',
		name: 'Ollama',
		description: 'Local models (free, requires Ollama)',
	},
]

export const OLLAMA_MODELS = [
	{
		id: 'llama3.2:latest',
		name: 'llama3.2:latest',
		description: "Meta's Llama 3.2 (latest)",
	},
	{
		id: 'llama3.1:latest',
		name: 'llama3.1:latest',
		description: "Meta's Llama 3.1",
	},
	{
		id: 'llama3:latest',
		name: 'llama3:latest',
		description: "Meta's Llama 3",
	},
	{
		id: 'llama2:latest',
		name: 'llama2:latest',
		description: "Meta's Llama 2",
	},
	{
		id: 'mistral:7b',
		name: 'mistral:7b',
		description: 'Mistral 7B model',
	},
	{
		id: 'mixtral:8x7b',
		name: 'mixtral:8x7b',
		description: "Mistral's Mixtral 8x7B model",
	},
	{
		id: 'phi3:mini',
		name: 'phi3:mini',
		description: "Microsoft's Phi-3 model",
	},
	{
		id: 'codellama:latest',
		name: 'codellama:latest',
		description: 'Code-specialized Llama',
	},
	{
		id: 'gemma2:latest',
		name: 'gemma2:latest',
		description: "Google's Gemma 2",
	},
	{
		id: 'nomic-embed-text:latest',
		name: 'nomic-embed-text:latest',
		description: 'Nomic embeddings',
	},
]

export const CHAT_PROVIDERS = [
	{
		id: 'openai',
		name: 'OpenAI ChatGPT',
		description: 'GPT-4, GPT-3.5 models',
	},
	{
		id: 'fireworks',
		name: 'Fireworks AI',
		description: 'Fast OSS models',
	},
	{ id: 'ollama', name: 'Ollama', description: 'Local LLMs' },
]

export const OPENAI_EMBEDDING_MODELS = [
	{
		id: 'text-embedding-ada-002',
		name: 'text-embedding-ada-002',
		dimensions: '1536',
		cost: '$0.10/1M tokens',
	},
	{
		id: 'text-embedding-3-small',
		name: 'text-embedding-3-small',
		dimensions: '1536',
		cost: '$0.02/1M tokens',
	},
	{
		id: 'text-embedding-3-large',
		name: 'text-embedding-3-large',
		dimensions: '3072',
		cost: '$0.13/1M tokens',
	},
]

export const OPENAI_CHAT_MODELS = [
	{
		id: 'gpt-4-turbo',
		name: 'GPT-4 Turbo',
		contextWindow: '128K',
		cost: '$10.00/$30.00',
	},
	{
		id: 'gpt-4',
		name: 'GPT-4',
		contextWindow: '8K',
		cost: '$30.00/$60.00',
	},
	{
		id: 'gpt-3.5-turbo',
		name: 'GPT-3.5 Turbo',
		contextWindow: '16K',
		cost: '$0.50/$1.50',
	},
]

export const FIREWORKS_EMBEDDING_MODELS = [
	{
		id: 'nomic-ai/nomic-embed-text-v1.5',
		name: 'Nomic Embed Text v1.5',
		dimensions: '768',
		cost: '$0.008/1M tokens',
	},
	{
		id: 'WhereIsAI/UAE-Large-V1',
		name: 'UAE Large V1',
		dimensions: '1024',
		cost: '$0.016/1M tokens',
	},
	{
		id: 'thenlper/gte-large',
		name: 'GTE Large',
		dimensions: '1024',
		cost: '$0.016/1M tokens',
	},
	{
		id: 'thenlper/gte-base',
		name: 'GTE Base',
		dimensions: '768',
		cost: '$0.008/1M tokens',
	},
]

export const FIREWORKS_CHAT_MODELS = [
	{
		id: 'accounts/fireworks/models/llama-v3p3-70b-instruct',
		name: 'Llama 3.3 70B',
		contextWindow: '131K',
		cost: '$0.9/1M',
	},
	{
		id: 'accounts/fireworks/models/llama-v3p1-405b-instruct',
		name: 'Llama 3.1 405B',
		contextWindow: '131K',
		cost: '$3/1M',
	},
	{
		id: 'accounts/fireworks/models/llama-v3p1-70b-instruct',
		name: 'Llama 3.1 70B',
		contextWindow: '131K',
		cost: '$0.9/1M',
	},
	{
		id: 'accounts/fireworks/models/llama-v3p1-8b-instruct',
		name: 'Llama 3.1 8B',
		contextWindow: '131K',
		cost: '$0.2/1M',
	},
	{
		id: 'accounts/fireworks/models/qwen2p5-72b-instruct',
		name: 'Qwen 2.5 72B',
		contextWindow: '32K',
		cost: '$0.9/1M',
	},
	{
		id: 'accounts/fireworks/models/deepseek-r1',
		name: 'DeepSeek R1',
		contextWindow: '163K',
		cost: '$3/1M',
	},
	{
		id: 'accounts/fireworks/models/mixtral-8x22b-instruct',
		name: 'Mixtral 8x22B',
		contextWindow: '64K',
		cost: '$1.2/1M',
	},
]

export const AI_FEATURES = [
	{
		id: 'text_generation',
		label: 'Text Generation',
		icon: '✍️',
		enabled: true,
	},
	{
		id: 'summarization',
		label: 'Document Summarization',
		icon: '📋',
		enabled: true,
	},
	{
		id: 'semantic_search',
		label: 'Semantic Search',
		icon: '🔍',
		enabled: true,
	},
	{
		id: 'embedding',
		label: 'Text Embeddings',
		icon: '🧮',
		enabled: true,
	},
	{
		id: 'translation',
		label: 'Translation',
		icon: '🌍',
		enabled: false,
	},
	{
		id: 'classification',
		label: 'Content Classification',
		icon: '🏷️',
		enabled: false,
	},
]
