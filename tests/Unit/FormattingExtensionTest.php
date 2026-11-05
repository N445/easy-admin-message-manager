<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Tests\Unit;

use EasyAdminMessengerManager\Twig\FormattingExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class FormattingExtensionTest extends TestCase
{
    public function testNumbersFollowTheLocale(): void
    {
        self::assertSame('1,234,567', $this->extension('en')->number(1234567));
        self::assertSame("1\u{202F}234\u{202F}567", $this->extension('fr')->number('1234567'));
        self::assertSame('1.5', $this->extension('en')->number(1.49, 1));
        self::assertSame('—', $this->extension('en')->number(null));
    }

    public function testRelativeTimeUsesTheLargestUnit(): void
    {
        $now = new \DateTimeImmutable('2026-01-10 12:00:00');
        $extension = $this->extension('en');

        self::assertSame('eamm.time.seconds:42', $extension->relative(new \DateTimeImmutable('2026-01-10 11:59:18'), $now));
        self::assertSame('eamm.time.minutes:5', $extension->relative('2026-01-10 11:55:00', $now));
        self::assertSame('eamm.time.hours:3', $extension->relative('2026-01-10 09:00:00', $now));
        self::assertSame('eamm.time.days:2', $extension->relative('2026-01-08 11:00:00', $now));
        self::assertSame('eamm.time.seconds:0', $extension->relative('2026-01-10 12:30:00', $now));
    }

    public function testAgeShowsAtMostTwoUnits(): void
    {
        $now = new \DateTimeImmutable('2026-01-10 12:00:00');
        $extension = $this->extension('en');

        self::assertSame('eamm.duration.seconds:42', $extension->age('2026-01-10 11:59:18', $now));
        self::assertSame('eamm.duration.minutes:5', $extension->age('2026-01-10 11:54:30', $now));
        self::assertSame('eamm.duration.hours:3 eamm.duration.minutes:12', $extension->age('2026-01-10 08:48:00', $now));
        self::assertSame('eamm.duration.days:2', $extension->age('2026-01-08 11:30:00', $now));
        self::assertSame('—', $extension->age(null));
    }

    public function testDatetimeRendersATimeElementWithTheRelativeTimeAsTitle(): void
    {
        $twig = new Environment(new ArrayLoader());
        $extension = $this->extension('en');

        $html = $extension->datetime($twig, '2026-01-10 11:55:00');

        self::assertStringStartsWith('<time datetime="2026-01-10T11:55:00', $html);
        self::assertStringContainsString('title="eamm.time.', $html);
        self::assertStringEndsWith('>2026-01-10 11:55:00</time>', $html);
        self::assertSame('—', $extension->datetime($twig, null));
    }

    private function extension(string $locale): FormattingExtension
    {
        $translator = new class implements TranslatorInterface {
            use TranslatorTrait;

            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id.':'.$parameters['%count%'];
            }
        };
        $translator->setLocale($locale);

        return new FormattingExtension($translator);
    }
}
