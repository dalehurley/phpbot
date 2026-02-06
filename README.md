# PhpBot

PhpBot is a PHP CLI that turns natural‑language requests into concrete actions. It analyzes the request, chooses an agent strategy, and executes tasks using built‑in tools (like bash and file ops). It can also create and persist new tools for repeated workflows.

## What’s In This Repo

- `bin/` — CLI entrypoint (`phpbot`) and launcher scripts.
- `config/` — Runtime configuration (`config/phpbot.php`).
- `src/` — Application code (agents, tools, orchestration).
- `skills/` — Built‑in skills and references.
- `storage/` — Persisted tools and runtime artifacts.
- `frontend/` — Optional UI assets (if enabled).
- `vendor/` — Composer dependencies (not committed).

## How It Works (High Level)

1. **Analyze**: Classifies the task, complexity, and success criteria.
2. **Select**: Chooses an agent strategy (react, plan_execute, reflection).
3. **Execute**: Runs tools (bash, filesystem, etc.) to complete the task.
4. **Evolve**: Newly created tools are saved to `storage/tools/`.

## Installation

1. Clone the repository.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Set your Anthropic API key:
   ```bash
   export ANTHROPIC_API_KEY='your-api-key-here'
   ```

## Usage

Interactive mode:

```bash
./bin/phpbot -i
# or simply
./bin/phpbot
```

Single command:

```bash
./bin/phpbot "List all PHP files in the current directory"
./bin/phpbot -c "Create a tool that fetches weather data"
```

Options:

```
-h, --help         Show help message
-V, --version      Show version information
-v, --verbose      Enable verbose output
-i, --interactive  Run in interactive mode
-l, --list-tools   List all available tools
-c, --command      Run a single command
```

## Configuration

Configuration lives in `config/phpbot.php`:

```php
return [
    'api_key' => getenv('ANTHROPIC_API_KEY'),
    'model' => 'claude-sonnet-4-5',
    'max_iterations' => 20,
    'max_tokens' => 4096,
    'temperature' => 0.7,
    'tools_storage_path' => dirname(__DIR__) . '/storage/tools',
];
```

## Tool Storage

Custom tools are stored as JSON in `storage/tools/`. Each tool includes:
- Name and description
- Parameter definitions
- Handler code (PHP)
- Category for organization

## Developing

Common tasks:

```bash
composer install
composer dump-autoload
```

If you add new tools or agents, keep the public behavior documented here and ensure the CLI help output stays accurate.

## License

MIT


## Frontend UI

Run the PHP API server:

```bash
php -d max_execution_time=0 -S localhost:8787 -t public
```

Start the WebSocket server (for live progress updates):

```bash
composer install
php bin/ws-server.php
```

Then start the Vite frontend:

```bash
cd frontend
npm install
npm run dev
```

The UI will be available at `http://localhost:5173` and will proxy `/api/*` requests to the PHP server.

You can override the WebSocket URL in the frontend by setting `VITE_WS_URL` in `frontend/.env`.

You can also run everything in parallel with:

```bash
composer run web
```

The Advanced controls panel lets power users override model routing, token budgets, and iteration counts per run. The Skill Builder and Tool Builder panels generate structured prompts to create new skills or tools directly from the UI. The Output panel now includes server log tails, full log fetches, and live WebSocket progress updates for debugging failed runs.
