<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Storage\RollbackManager;
use Dalehurley\Phpbot\Tools\RollbackTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(RollbackTool::class)]
class RollbackToolTest extends TestCase
{
    private RollbackTool $tool;

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testGetName(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $tool = new RollbackTool($manager);
        $this->assertSame('rollback', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $tool = new RollbackTool($manager);
        $this->assertStringContainsString('roll back', strtolower($tool->getDescription()));
    }

    public function testExecuteListAction(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $manager->shouldReceive('listSessions')
            ->once()
            ->andReturn([
                ['session_id' => 's1', 'created_at' => '2025-01-01', 'file_count' => 2],
            ]);

        $tool = new RollbackTool($manager);
        $result = $tool->execute(['action' => 'list']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertArrayHasKey('sessions', $data);
        $this->assertCount(1, $data['sessions']);
    }

    public function testExecuteListActionEmpty(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $manager->shouldReceive('listSessions')->andReturn([]);

        $tool = new RollbackTool($manager);
        $result = $tool->execute(['action' => 'list']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame([], $data['sessions']);
    }

    public function testExecuteRollbackActionWithoutSessionId(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $tool = new RollbackTool($manager);
        $result = $tool->execute(['action' => 'rollback']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('session_id', $result->getContent());
    }

    public function testExecuteRollbackAction(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $manager->shouldReceive('rollback')
            ->once()
            ->with('sess-123')
            ->andReturn(['restored' => ['/a.txt'], 'deleted' => ['/b.txt'], 'errors' => []]);

        $tool = new RollbackTool($manager);
        $result = $tool->execute(['action' => 'rollback', 'session_id' => 'sess-123']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('sess-123', $data['session_id']);
        $this->assertCount(1, $data['restored']);
        $this->assertCount(1, $data['deleted']);
    }

    public function testExecuteUnknownAction(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $tool = new RollbackTool($manager);
        $result = $tool->execute(['action' => 'invalid']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Unknown action', $result->getContent());
    }

    public function testExecuteRollbackThrows(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $manager->shouldReceive('rollback')->andThrow(new \RuntimeException('rollback failed'));

        $tool = new RollbackTool($manager);
        $result = $tool->execute(['action' => 'rollback', 'session_id' => 's1']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('rollback failed', $result->getContent());
    }

    public function testToDefinition(): void
    {
        $manager = Mockery::mock(RollbackManager::class);
        $tool = new RollbackTool($manager);
        $def = $tool->toDefinition();
        $this->assertSame('rollback', $def['name']);
    }
}
