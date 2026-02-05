<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools\Promoted;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

if (!function_exists('bash')) {
    function bash(string $command, int $timeout = 60): string {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, getcwd());
        
        if (!is_resource($process)) {
            return 'ERROR: Failed to execute command';
        }

        fclose($pipes[0]);
        
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if (time() - $startTime > $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return 'ERROR: Command timed out';
            }

            usleep(10000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $stdout = trim($stdout);
        $stderr = trim($stderr);
        $exitCode = $status['exitcode'] ?? $exitCode;

        if ($exitCode !== 0 && $stderr !== '') {
            return "ERROR: {$stderr}";
        }

        if ($exitCode !== 0 && $stdout !== '') {
            return "ERROR: {$stdout}";
        }

        return $stdout;
    }
}

class ReadWordDocTool implements ToolInterface
{
    private array $parameters = array (
  0 => 
  array (
    'name' => 'file_path',
    'type' => 'string',
    'description' => 'Path to the Word document file (.doc or .docx)',
    'required' => true,
  ),
  1 => 
  array (
    'name' => 'preserve_formatting',
    'type' => 'boolean',
    'description' => 'Whether to attempt to preserve basic formatting like line breaks and paragraphs',
    'required' => false,
    'default' => true,
  ),
);

    public function getName(): string
    {
        return 'read_word_doc';
    }

    public function getDescription(): string
    {
        return 'Reads and extracts text content from Word documents (.doc and .docx files). Returns the plain text content of the document.';
    }

    public function getCategory(): string
    {
        return 'file_ops';
    }

    public function getInputSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $param) {
            $prop = [
                'type' => $param['type'],
                'description' => $param['description'],
            ];

            if (isset($param['default'])) {
                $prop['default'] = $param['default'];
            }

            if (isset($param['enum'])) {
                $prop['enum'] = $param['enum'];
            }

            $properties[$param['name']] = $prop;

            if (!empty($param['required'])) {
                $required[] = $param['name'];
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        try {
            foreach ($this->parameters as $param) {
                if (!isset($input[$param['name']]) && isset($param['default'])) {
                    $input[$param['name']] = $param['default'];
                }
            }

            $handler = function(array $input) {
                
$filePath = $input['file_path'];
$preserveFormatting = $input['preserve_formatting'] ?? true;

// Check if file exists
if (!file_exists($filePath)) {
    return ['error' => 'File not found: ' . $filePath];
}

// Check file extension
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
if (!in_array($extension, ['doc', 'docx'])) {
    return ['error' => 'Invalid file type. Only .doc and .docx files are supported.'];
}

// Create a temporary PHP script to read the document
$scriptPath = sys_get_temp_dir() . '/read_word_' . uniqid() . '.php';
$preserveFormattingStr = $preserveFormatting ? 'true' : 'false';

// Find vendor autoload - check common locations
$vendorPaths = [
    '/tmp/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$vendorPath = null;
foreach ($vendorPaths as $path) {
    if (file_exists($path)) {
        $vendorPath = $path;
        break;
    }
}

if (!$vendorPath) {
    return ['error' => 'PHPWord library not found. Please ensure phpoffice/phpword is installed.'];
}

$script = <<<PHPSCRIPT
<?php
require_once '$vendorPath';

\$filePath = \$argv[1];
\$preserveFormatting = \$argv[2] === 'true';

try {
    \$phpWord = \PhpOffice\PhpWord\IOFactory::load(\$filePath);
    \$text = '';
    
    function extractText(\$elements, \$preserveFormatting) {
        \$result = '';
        foreach (\$elements as \$element) {
            if (\$element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                foreach (\$element->getElements() as \$textElement) {
                    if (method_exists(\$textElement, 'getText')) {
                        \$result .= \$textElement->getText();
                    }
                }
                if (\$preserveFormatting) {
                    \$result .= "\n";
                }
            } elseif (\$element instanceof \PhpOffice\PhpWord\Element\Text) {
                \$result .= \$element->getText();
                if (\$preserveFormatting) {
                    \$result .= "\n";
                }
            } elseif (\$element instanceof \PhpOffice\PhpWord\Element\Table) {
                foreach (\$element->getRows() as \$row) {
                    foreach (\$row->getCells() as \$cell) {
                        \$result .= extractText(\$cell->getElements(), \$preserveFormatting);
                        \$result .= "\t";
                    }
                    if (\$preserveFormatting) {
                        \$result .= "\n";
                    }
                }
            } elseif (method_exists(\$element, 'getElements')) {
                \$result .= extractText(\$element->getElements(), \$preserveFormatting);
            }
        }
        return \$result;
    }
    
    foreach (\$phpWord->getSections() as \$section) {
        \$text .= extractText(\$section->getElements(), \$preserveFormatting);
        if (\$preserveFormatting) {
            \$text .= "\n";
        }
    }
    
    if (\$preserveFormatting) {
        \$text = preg_replace('/\n{3,}/', "\n\n", \$text);
        \$text = preg_replace('/[ \t]+/', ' ', \$text);
    }
    
    \$text = trim(\$text);
    
    echo json_encode([
        'success' => true,
        'content' => \$text,
        'length' => strlen(\$text),
        'word_count' => str_word_count(\$text)
    ]);
} catch (Exception \$e) {
    echo json_encode(['error' => \$e->getMessage()]);
    exit(1);
}
PHPSCRIPT;

file_put_contents($scriptPath, $script);

// Execute the script
$command = "php " . escapeshellarg($scriptPath) . " " . escapeshellarg($filePath) . " " . escapeshellarg($preserveFormattingStr) . " 2>&1";
$output = bash($command);

// Clean up
unlink($scriptPath);

// Check for errors
if (strpos($output, 'ERROR:') === 0) {
    return ['error' => substr($output, 7)];
}

$result = json_decode($output, true);
if (!$result) {
    return ['error' => 'Failed to parse output: ' . $output];
}

if (isset($result['error'])) {
    return ['error' => 'Failed to read Word document: ' . $result['error']];
}

$result['file'] = $filePath;
return $result;

            };

            $result = $handler($input);

            if (is_array($result)) {
                return ToolResult::success(json_encode($result));
            }

            return ToolResult::success((string) $result);
        } catch (\Throwable $e) {
            return ToolResult::error("Tool execution failed: " . $e->getMessage());
        }
    }

    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }
}