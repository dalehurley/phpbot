<?php

declare(strict_types=1);

namespace Tests\Storage;

use Dalehurley\Phpbot\Storage\KeyStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyStore::class)]
class KeyStoreTest extends TestCase
{
    private string $tmpDir;
    private KeyStore $store;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-keystore-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->store = new KeyStore($this->tmpDir . '/store.json');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testGetReturnsNullForNonExistentKey(): void
    {
        $this->assertNull($this->store->get('unknown'));
    }

    public function testGetReturnsNullWhenFileDoesNotExist(): void
    {
        $store = new KeyStore($this->tmpDir . '/nonexistent.json');
        $this->assertNull($store->get('any'));
    }

    public function testSetAndGet(): void
    {
        $this->store->set('foo', 'bar');
        $this->assertSame('bar', $this->store->get('foo'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->store->set('key', 'old');
        $this->store->set('key', 'new');
        $this->assertSame('new', $this->store->get('key'));
    }

    public function testMultipleKeys(): void
    {
        $this->store->set('a', '1');
        $this->store->set('b', '2');
        $this->store->set('c', '3');
        $this->assertSame('1', $this->store->get('a'));
        $this->assertSame('2', $this->store->get('b'));
        $this->assertSame('3', $this->store->get('c'));
    }

    public function testGetReturnsNullForNonStringValue(): void
    {
        $path = $this->tmpDir . '/store.json';
        file_put_contents($path, json_encode(['key' => 123]));
        $store = new KeyStore($path);
        $this->assertNull($store->get('key'));
    }

    public function testGetReturnsNullForInvalidJson(): void
    {
        file_put_contents($this->tmpDir . '/store.json', 'not valid json');
        $store = new KeyStore($this->tmpDir . '/store.json');
        $this->assertNull($store->get('key'));
    }

    public function testGetReturnsNullForJsonArrayInsteadOfObject(): void
    {
        file_put_contents($this->tmpDir . '/store.json', '[1, 2, 3]');
        $store = new KeyStore($this->tmpDir . '/store.json');
        $this->assertNull($store->get('0'));
    }

    public function testSetCreatesDirectoryIfMissing(): void
    {
        $deepPath = $this->tmpDir . '/sub/dir/store.json';
        $store = new KeyStore($deepPath);
        $store->set('x', 'y');
        $this->assertSame('y', $store->get('x'));
        $this->assertFileExists($deepPath);
    }

    public function testDataPersistsAcrossInstances(): void
    {
        $path = $this->tmpDir . '/persist.json';
        $store1 = new KeyStore($path);
        $store1->set('persisted', 'value');
        $store2 = new KeyStore($path);
        $this->assertSame('value', $store2->get('persisted'));
    }

    public function testEmptyValueStoredAsString(): void
    {
        $this->store->set('empty', '');
        $this->assertSame('', $this->store->get('empty'));
    }

    public function testUnicodeValue(): void
    {
        $this->store->set('unicode', '日本語テスト');
        $this->assertSame('日本語テスト', $this->store->get('unicode'));
    }
}
