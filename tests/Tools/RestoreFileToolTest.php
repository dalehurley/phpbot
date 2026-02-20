<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Storage\BackupManager;
use Dalehurley\Phpbot\Tools\RestoreFileTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(RestoreFileTool::class)]
class RestoreFileToolTest extends TestCase
{
    private RestoreFileTool $tool;

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testGetName(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $tool = new RestoreFileTool($manager);
        $this->assertSame('restore_file', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $tool = new RestoreFileTool($manager);
        $this->assertStringContainsString('restore', strtolower($tool->getDescription()));
    }

    public function testExecuteEmptyPathReturnsError(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $tool = new RestoreFileTool($manager);
        $result = $tool->execute(['action' => 'list', 'path' => '']);
        $this->assertTrue($result->isError());
    }

    public function testExecuteListAction(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('listBackups')
            ->once()
            ->with('/path/to/file.txt')
            ->andReturn([
                ['version' => 1, 'date' => '2025-01-01', 'path' => '/backup/file.txt.1', 'size' => 100, 'modified' => 1234567890],
            ]);

        $tool = new RestoreFileTool($manager);
        $result = $tool->execute(['action' => 'list', 'path' => '/path/to/file.txt']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertArrayHasKey('backups', $data);
        $this->assertCount(1, $data['backups']);
    }

    public function testExecuteListActionEmpty(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('listBackups')->andReturn([]);

        $tool = new RestoreFileTool($manager);
        $result = $tool->execute(['action' => 'list', 'path' => '/file.txt']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame([], $data['backups']);
    }

    public function testExecuteRestoreAction(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('restore')
            ->once()
            ->with('/file.txt', null)
            ->andReturn('/backup/file.txt.1');

        $tool = new RestoreFileTool($manager);
        $result = $tool->execute(['action' => 'restore', 'path' => '/file.txt']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('/file.txt', $data['restored_file']);
        $this->assertTrue($data['success']);
    }

    public function testExecuteRestoreWithVersion(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('restore')
            ->once()
            ->with('/file.txt', 2)
            ->andReturn('/backup/file.txt.2');

        $tool = new RestoreFileTool($manager);
        $result = $tool->execute(['action' => 'restore', 'path' => '/file.txt', 'version' => 2]);
        $this->assertFalse($result->isError());
    }

    public function testExecuteRestoreThrows(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('restore')->andThrow(new \RuntimeException('No backups found'));

        $tool = new RestoreFileTool($manager);
        $result = $tool->execute(['action' => 'restore', 'path' => '/file.txt']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('No backups found', $result->getContent());
    }

    public function testExecuteUnknownAction(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $tool = new RestoreFileTool($manager);
        $result = $tool->execute(['action' => 'unknown', 'path' => '/file.txt']);
        $this->assertTrue($result->isError());
    }

    public function testToDefinition(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $tool = new RestoreFileTool($manager);
        $def = $tool->toDefinition();
        $this->assertSame('restore_file', $def['name']);
    }
}
