<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

/**
 * Provides the standard toDefinition() method for tools implementing ToolInterface.
 *
 * Eliminates identical boilerplate across BrewTool, GetKeysTool,
 * StoreKeysTool, SearchComputerTool, and any future tools.
 */
trait ToolDefinitionTrait
{
    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }
}
