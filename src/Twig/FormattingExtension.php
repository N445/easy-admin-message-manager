<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\TwigFilter;

final class FormattingExtension extends AbstractExtension
{
    private const string DOMAIN = 'EasyAdminMessengerManager';

    /** @var array<string, int> */
    private const array UNITS = ['days' => 86400, 'hours' => 3600, 'minutes' => 60];

    /** @var array<string, string> */
    private const array FINER_UNITS = ['days' => 'hours', 'hours' => 'minutes'];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('eamm_number', $this->number(...)),
            new TwigFilter('eamm_relative', $this->relative(...)),
            new TwigFilter('eamm_age', $this->age(...)),
            new TwigFilter('eamm_datetime', $this->datetime(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    public function number(int|float|string|null $value, int $decimals = 0): string
    {
        if (null === $value || '' === $value) {
            return '—';
        }

        if (!is_numeric($value)) {
            return $value;
        }

        $value += 0;

        if (!class_exists(\NumberFormatter::class)) {
            return number_format($value, $decimals);
        }

        $formatter = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);

        return (string) $formatter->format($value);
    }

    public function relative(\DateTimeInterface|string|null $date, ?\DateTimeInterface $now = null): string
    {
        if (null === $date || '' === $date) {
            return '';
        }

        $seconds = max(0, ($now ?? new \DateTimeImmutable())->getTimestamp() - $this->toDate($date)->getTimestamp());
        foreach (self::UNITS as $unit => $length) {
            if ($seconds >= $length) {
                return $this->ago($unit, intdiv($seconds, $length));
            }
        }

        return $this->ago('seconds', $seconds);
    }

    public function age(\DateTimeInterface|string|null $date, ?\DateTimeInterface $now = null): string
    {
        if (null === $date || '' === $date) {
            return '—';
        }

        $seconds = max(0, ($now ?? new \DateTimeImmutable())->getTimestamp() - $this->toDate($date)->getTimestamp());
        foreach (self::UNITS as $unit => $length) {
            if ($seconds >= $length) {
                return trim($this->span($unit, intdiv($seconds, $length)).' '.$this->finerSpan($unit, $seconds % $length));
            }
        }

        return $this->span('seconds', $seconds);
    }

    public function datetime(Environment $twig, \DateTimeInterface|string|null $date): string
    {
        if (null === $date || '' === $date) {
            return '—';
        }

        $converted = $twig->getExtension(CoreExtension::class)->convertDate($this->toDate($date));

        return \sprintf(
            '<time datetime="%s" title="%s">%s</time>',
            $converted->format(\DATE_ATOM),
            htmlspecialchars($this->relative($converted), \ENT_QUOTES),
            $converted->format('Y-m-d H:i:s'),
        );
    }

    private function ago(string $unit, int $count): string
    {
        return $this->translator->trans('eamm.time.'.$unit, ['%count%' => $count], self::DOMAIN);
    }

    private function span(string $unit, int $count): string
    {
        return $this->translator->trans('eamm.duration.'.$unit, ['%count%' => $count], self::DOMAIN);
    }

    private function finerSpan(string $unit, int $seconds): string
    {
        $finer = self::FINER_UNITS[$unit] ?? null;
        if (null === $finer || $seconds < self::UNITS[$finer]) {
            return '';
        }

        return $this->span($finer, intdiv($seconds, self::UNITS[$finer]));
    }

    private function toDate(\DateTimeInterface|string $date): \DateTimeInterface
    {
        return \is_string($date) ? new \DateTimeImmutable($date) : $date;
    }
}
