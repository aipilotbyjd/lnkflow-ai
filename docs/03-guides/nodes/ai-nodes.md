# AI Nodes

LinkFlow integrates with powerful LLMs to add intelligence to your workflows.

## OpenAI Node

### Configuration
-   **Model**: `gpt-4o`, `gpt-4-turbo`, `gpt-3.5-turbo`.
-   **Prompt**: The instruction to the AI. You can use variables: `Summarize this email: {{ email.body }}`.
-   **Temperature**: Controls randomness (0 = deterministic, 1 = creative).

### Use Cases
-   **Summarization**: Condense long text or meeting notes.
-   **Extraction**: Pull specific data (dates, names, amounts) from unstructured text.
-   **Classification**: Tag support tickets by sentiment or topic.
-   **Generation**: Write draft responses to emails.

## Anthropic Node

Similar to OpenAI but uses the Claude models (`claude-3-opus`, `claude-3-sonnet`). Good for large context windows and reasoning tasks.

## Local AI (Ollama)

For privacy-focused or cost-effective AI, you can connect to a local Ollama instance running `llama3` or `mistral`.
-   **Endpoint**: URL of your Ollama server.
-   **Model**: Name of the model to use.
