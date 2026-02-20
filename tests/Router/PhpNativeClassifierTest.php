<?php

declare(strict_types=1);

namespace Tests\Router;

use Dalehurley\Phpbot\Router\PhpNativeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpNativeClassifier::class)]
class PhpNativeClassifierTest extends TestCase
{
    private function sampleCategories(): array
    {
        return [
            [
                'id' => 'send-sms',
                'patterns' => ['send sms', 'send text message', 'text someone', 'send message | text message'],
            ],
            [
                'id' => 'create-file',
                'patterns' => ['create file', 'make file', 'new file', 'write file', 'generate file'],
            ],
            [
                'id' => 'search',
                'patterns' => ['search for', 'find file', 'look for', 'locate'],
            ],
        ];
    }

    public function testClassifyExactPhraseMatch(): void
    {
        $classifier = new PhpNativeClassifier();
        $categories = $this->sampleCategories();

        $result = $classifier->classify('send sms to my friend', $categories);

        $this->assertNotNull($result);
        $this->assertSame('send-sms', $result['category']['id']);
        $this->assertGreaterThanOrEqual(0.35, $result['confidence']);
    }

    public function testClassifySynonymMatch(): void
    {
        $classifier = new PhpNativeClassifier();
        $categories = $this->sampleCategories();

        $result = $classifier->classify('make a new file', $categories);

        $this->assertNotNull($result);
        $this->assertSame('create-file', $result['category']['id']);
    }

    public function testClassifyReturnsNullForEmptyInput(): void
    {
        $classifier = new PhpNativeClassifier();
        $result = $classifier->classify('', $this->sampleCategories());
        $this->assertNull($result);
    }

    public function testClassifyReturnsNullForNoMatch(): void
    {
        $classifier = new PhpNativeClassifier(0.99);
        $categories = [['id' => 'xyz', 'patterns' => ['completely unrelated thing']]];
        $result = $classifier->classify('weather forecast today', $categories);
        $this->assertNull($result);
    }

    public function testClassifyRespectsMinConfidence(): void
    {
        $classifier = new PhpNativeClassifier(0.99);
        $result = $classifier->classify('send sms', $this->sampleCategories());
        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.99, $result['confidence']);
    }

    public function testClassifyWithLogger(): void
    {
        $logMessages = [];
        $classifier = new PhpNativeClassifier();
        $classifier->setLogger(function (string $msg) use (&$logMessages): void {
            $logMessages[] = $msg;
        });

        $classifier->classify('send sms to john', $this->sampleCategories());

        $this->assertNotEmpty($logMessages);
        $this->assertStringContainsString('send-sms', $logMessages[0]);
    }

    public function testClassifyEmptyCategories(): void
    {
        $classifier = new PhpNativeClassifier();
        $result = $classifier->classify('anything', []);
        $this->assertNull($result);
    }

    public function testClassifyCategoryWithEmptyPatterns(): void
    {
        $classifier = new PhpNativeClassifier();
        $categories = [
            ['id' => 'empty', 'patterns' => []],
            ['id' => 'send-sms', 'patterns' => ['send sms']],
        ];
        $result = $classifier->classify('send sms', $categories);
        $this->assertNotNull($result);
        $this->assertSame('send-sms', $result['category']['id']);
    }

    public function testClassifyMorphologicalMatch(): void
    {
        $classifier = new PhpNativeClassifier();
        $categories = [
            ['id' => 'create', 'patterns' => ['create file', 'creating files']],
        ];
        $result = $classifier->classify('creating a file', $categories);
        $this->assertNotNull($result);
        $this->assertSame('create', $result['category']['id']);
    }

    public function testClassifyStopWordsFiltered(): void
    {
        $classifier = new PhpNativeClassifier();
        $categories = [
            ['id' => 'send', 'patterns' => ['send to the user']],
        ];
        $result = $classifier->classify('I want to send to the user a message', $categories);
        $this->assertNotNull($result);
    }

    public function testClassifyAlternativePatterns(): void
    {
        $classifier = new PhpNativeClassifier();
        $categories = [
            ['id' => 'multi', 'patterns' => ['option a | option b | primary action']],
        ];
        $result = $classifier->classify('I need option b', $categories);
        $this->assertNotNull($result);
        $this->assertSame('multi', $result['category']['id']);
    }

    public function testClassifyCustomMinConfidence(): void
    {
        $classifier = new PhpNativeClassifier(0.5);
        $result = $classifier->classify('search for files', $this->sampleCategories());
        $this->assertNotNull($result);
        $this->assertSame('search', $result['category']['id']);
    }
}