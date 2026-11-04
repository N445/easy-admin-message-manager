<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Payload\PayloadNormalizer;
use EasyAdminMessengerManager\Tests\App\TestMessage;
use PHPUnit\Framework\TestCase;

final class PayloadNormalizerTest extends TestCase
{
    public function testObjectsBecomeReadableJsonWithTheirClass(): void
    {
        $data = json_decode((new PayloadNormalizer([]))->json(new TestMessage('visible-label')), true);

        self::assertSame(TestMessage::class, $data['@class']);
        self::assertSame('visible-label', $data['label']);
        self::assertFalse($data['fails']);
    }

    public function testRedactsConfiguredKeysAndSensitiveParameters(): void
    {
        $json = (new PayloadNormalizer(['Password', 'token']))->json(['token' => 'abc', 'message' => new TestMessage('x')]);

        self::assertStringNotContainsString('p4ss', $json);
        self::assertStringNotContainsString('hidden-value', $json);
        self::assertStringNotContainsString('abc', $json);
        self::assertStringContainsString(PayloadNormalizer::MASK, $json);
    }

    public function testHandlesScalarsEnumsDatesAndBinary(): void
    {
        $normalizer = new PayloadNormalizer([]);

        self::assertSame('2026-01-02T03:04:05+00:00', $normalizer->normalize(new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))));
        self::assertSame('INF', $normalizer->normalize(\INF));
        self::assertSame('binary (2 bytes)', $normalizer->normalize("\xff\xfe"));
        self::assertSame('Closure', $normalizer->normalize(static fn () => null));
    }

    public function testJsonCannotCloseTheScriptTagItIsEmbeddedIn(): void
    {
        $json = (new PayloadNormalizer([]))->json(['html' => '</script><script>alert(1)</script>']);

        self::assertStringNotContainsString('</script>', $json);
        self::assertSame('</script><script>alert(1)</script>', json_decode($json, true)['html']);
    }

    public function testStopsOnRecursionAndDepth(): void
    {
        $node = new \stdClass();
        $node->self = $node;

        $data = (new PayloadNormalizer([]))->normalize($node);

        self::assertSame('stdClass (recursion)', $data['self']);
    }

    public function testIncompleteClassesKeepTheirOriginalName(): void
    {
        $incomplete = unserialize('O:16:"App\RemovedClass":1:{s:2:"id";i:42;}');

        $data = (new PayloadNormalizer([]))->normalize($incomplete);

        self::assertSame(['@class' => 'App\RemovedClass', 'id' => 42], $data);
    }
}
