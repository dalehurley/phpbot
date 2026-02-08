<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use Dalehurley\Phpbot\Tools\BashTool;
use Dalehurley\Phpbot\Tools\ReadFileTool;
use Dalehurley\Phpbot\Tools\WriteFileTool;
use Dalehurley\Phpbot\Tools\EditFileTool;
use Dalehurley\Phpbot\Tools\SkillScriptTool;
use Dalehurley\Phpbot\Tools\AskUserTool;
use Dalehurley\Phpbot\Tools\GetKeysTool;
use Dalehurley\Phpbot\Tools\StoreKeysTool;
use Dalehurley\Phpbot\Tools\SearchComputerTool;
use Dalehurley\Phpbot\Tools\SearchCapabilitiesTool;
use Dalehurley\Phpbot\Tools\BrewTool;
use Dalehurley\Phpbot\Tools\ToolBuilderTool;
use Dalehurley\Phpbot\Tools\ToolPromoterTool;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;
use Dalehurley\Phpbot\Router\RouteResult;

class ToolRegistrar
{
    private ?SearchCapabilitiesTool $searchCapabilitiesTool = null;

    public function __construct(
        private PersistentToolRegistry $registry,
        private array $config
    ) {}

    public function getRegistry(): PersistentToolRegistry
    {
        return $this->registry;
    }

    /**
     * Register the SearchCapabilitiesTool with its dependencies.
     * Called from Bot after SkillManager is initialized.
     */
    public function registerSearchCapabilitiesTool(
        ?\ClaudeAgents\Skills\SkillManager $skillManager = null,
    ): void {
        $this->searchCapabilitiesTool = new SearchCapabilitiesTool($skillManager, $this->registry);
        $this->registry->register($this->searchCapabilitiesTool);
    }

    public function registerCoreTools(): void
    {
        $storagePath = $this->config['files_storage_path'] ?? '';
        $this->registry->register(new BashTool($this->config));
        $this->registry->register(new ReadFileTool());
        $this->registry->register(new WriteFileTool($storagePath));
        $this->registry->register(new EditFileTool());
        $this->registry->register(new AskUserTool());
        $this->registry->register(new GetKeysTool($this->config));
        $this->registry->register(new StoreKeysTool($this->config));
        $this->registry->register(new SearchComputerTool());
        $this->registry->register(new BrewTool($this->config));

        $this->registry->register(new ToolBuilderTool($this->registry));
        $this->registry->register(new ToolPromoterTool($this->registry));

        $this->registry->loadPersistedTools();

        $this->registerPromotedTools();
    }

    /** Core tools that are always selected when registered. */
    private const CORE_TOOL_NAMES = [
        'bash',
        'write_file',
        'read_file',
        'edit_file',
        'ask_user',
        'get_keys',
        'store_keys',
        'search_computer',
        'brew',
        'tool_builder',
        'tool_promoter',
    ];

    /** Minimal tools always present for routed requests. */
    private const MINIMAL_TOOL_NAMES = [
        'bash',
        'search_capabilities',
        'get_keys',
        'store_keys',
        'search_computer',
        'ask_user',
    ];

    /**
     * Select tools for an agent run.
     *
     * When a RouteResult is provided, only the tools specified by the
     * router are loaded (plus bash + search_capabilities). This avoids
     * sending all 12+ core tool schemas for simple tasks.
     *
     * Falls back to loading all core tools when no RouteResult is given.
     *
     * @param array $analysis Task analysis array
     * @param RouteResult|null $routeResult Optional route result for selective loading
     * @return array<\ClaudeAgents\Contracts\ToolInterface>
     */
    public function selectTools(array $analysis, ?RouteResult $routeResult = null): array
    {
        // Selective loading when router provides a tool list
        if ($routeResult !== null && !$routeResult->isEarlyExit()) {
            return $this->selectRoutedTools($routeResult);
        }

        // Legacy path: load all core tools
        return $this->selectAllCoreTools($analysis);
    }

    /**
     * Select only the tools specified by the router.
     *
     * @return array<\ClaudeAgents\Contracts\ToolInterface>
     */
    private function selectRoutedTools(RouteResult $routeResult): array
    {
        $tools = [];
        $included = [];

        // Always include minimal tools
        foreach (self::MINIMAL_TOOL_NAMES as $name) {
            if ($this->registry->has($name)) {
                $tools[] = $this->registry->get($name);
                $included[$name] = true;
            }
        }

        // Include tools specified by the router
        foreach ($routeResult->tools as $toolName) {
            if (!isset($included[$toolName]) && $this->registry->has($toolName)) {
                $tools[] = $this->registry->get($toolName);
                $included[$toolName] = true;
            }
        }

        return $tools;
    }

    /**
     * Legacy: select all core tools plus analysis-suggested tools.
     *
     * @return array<\ClaudeAgents\Contracts\ToolInterface>
     */
    private function selectAllCoreTools(array $analysis): array
    {
        $tools = [];
        $included = [];

        // 1. Include all registered core tools
        foreach (self::CORE_TOOL_NAMES as $name) {
            if ($this->registry->has($name)) {
                $tools[] = $this->registry->get($name);
                $included[$name] = true;
            }
        }

        // 1b. Include search_capabilities if registered
        if (!isset($included['search_capabilities']) && $this->registry->has('search_capabilities')) {
            $tools[] = $this->registry->get('search_capabilities');
            $included['search_capabilities'] = true;
        }

        // 2. Include custom (user-created) tools
        $customTools = $this->registry->getCustomTools();
        foreach ($customTools as $tool) {
            $tools[] = $tool;
        }

        // 3. Include analysis-suggested tools not already present
        if (!empty($analysis['potential_tools_needed'])) {
            foreach ($analysis['potential_tools_needed'] as $toolName) {
                if (!isset($included[$toolName]) && $this->registry->has($toolName)) {
                    $tools[] = $this->registry->get($toolName);
                }
            }
        }

        return $tools;
    }

    public function registerSkillScriptTools(string $skillsPath): void
    {
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return;
        }

        $scriptFiles = $this->findSkillScripts($skillsPath);
        foreach ($scriptFiles as $scriptPath) {
            $tool = $this->createScriptTool($skillsPath, $scriptPath);
            if ($tool !== null && !$this->registry->has($tool->getName())) {
                $this->registry->register($tool);
            }
        }
    }

    public function listSkillScripts(string $skillsPath): array
    {
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return [];
        }

        $scripts = $this->findSkillScripts($skillsPath);
        $tools = [];

        foreach ($scripts as $scriptPath) {
            $tool = $this->createScriptTool($skillsPath, $scriptPath);
            if ($tool !== null) {
                $tools[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'script' => $scriptPath,
                ];
            }
        }

        return $tools;
    }

    private function registerPromotedTools(): void
    {
        $promotedDir = dirname(__DIR__) . '/src/Tools/Promoted';
        if (!is_dir($promotedDir)) {
            return;
        }

        $files = glob($promotedDir . '/*.php') ?: [];
        foreach ($files as $file) {
            require_once $file;

            $className = pathinfo($file, PATHINFO_FILENAME);
            $fqcn = 'Dalehurley\\Phpbot\\Tools\\Promoted\\' . $className;

            if (!class_exists($fqcn)) {
                continue;
            }

            $tool = new $fqcn();
            if ($tool instanceof \ClaudeAgents\Contracts\ToolInterface) {
                $this->registry->register($tool);
            }
        }
    }

    /**
     * @return string[]
     */
    private function findSkillScripts(string $skillsPath): array
    {
        $scripts = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($skillsPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (strpos($path, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR) === false) {
                continue;
            }

            $extension = strtolower((string) $file->getExtension());
            if (!in_array($extension, ['py', 'sh', 'js', 'php'], true)) {
                continue;
            }

            $basename = $file->getBasename();
            if (str_starts_with($basename, '__')) {
                continue;
            }

            $scripts[] = $path;
        }

        return $scripts;
    }

    private function createScriptTool(string $skillsPath, string $scriptPath): ?SkillScriptTool
    {
        $extension = strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION));
        $interpreter = match ($extension) {
            'py' => 'python3',
            'sh' => 'bash',
            'js' => 'node',
            'php' => 'php',
            default => null,
        };

        if ($interpreter === null) {
            return null;
        }

        $relative = ltrim(str_replace($skillsPath, '', $scriptPath), DIRECTORY_SEPARATOR);
        $parts = preg_split('/[\/\\\\]+/', $relative);
        $skillName = $parts[0] ?? 'skill';
        $scriptBase = pathinfo($scriptPath, PATHINFO_FILENAME);

        $toolName = $this->toToolName($skillName, $scriptBase);
        $description = "Run {$scriptBase} script for {$skillName} skill";

        return new SkillScriptTool($toolName, $description, $scriptPath, $interpreter);
    }

    private function toToolName(string $skillName, string $scriptBase): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $skillName . '_' . $scriptBase));
        $slug = trim($slug, '_');
        return 'skill_' . $slug;
    }
}
