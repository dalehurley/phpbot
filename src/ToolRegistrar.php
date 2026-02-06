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
use Dalehurley\Phpbot\Tools\BrewTool;
use Dalehurley\Phpbot\Tools\ToolBuilderTool;
use Dalehurley\Phpbot\Tools\ToolPromoterTool;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;

class ToolRegistrar
{
    public function __construct(
        private PersistentToolRegistry $registry,
        private array $config
    ) {}

    public function getRegistry(): PersistentToolRegistry
    {
        return $this->registry;
    }

    public function registerCoreTools(): void
    {
        $this->registry->register(new BashTool($this->config));
        $this->registry->register(new ReadFileTool());
        $this->registry->register(new WriteFileTool());
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

    public function selectTools(array $analysis): array
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
