<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Twig;

use EasyAdminMessengerManager\Customization\MessageViewCustomizations;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CustomizationExtension extends AbstractExtension
{
    public function __construct(private readonly MessageViewCustomizations $customizations)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('eamm_columns', $this->customizations->columns(...)),
            new TwigFunction('eamm_actions', $this->customizations->actions(...)),
            new TwigFunction('eamm_label', $this->customizations->label(...)),
        ];
    }
}
