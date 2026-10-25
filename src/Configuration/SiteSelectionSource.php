<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Where the selection of the test site comes from.
 */
enum SiteSelectionSource: string
{
    case Option = '--site';
    case Environment = 'JXT_SITE';
    case DefaultSite = 'default_site';
    case None = 'none';
}
