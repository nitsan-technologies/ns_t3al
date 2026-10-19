<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Imaging;

use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * Renders SVG icons inline so `currentColor` and `--icon-color-accent`
 * follow the TYPO3 v14 backend theme (same approach as EXT:ns_t3ai).
 */
class InlineSvgIconProvider extends SvgIconProvider
{
    protected function generateMarkup(Icon $icon, array $options): string
    {
        return $this->generateInlineMarkup($options);
    }
}
