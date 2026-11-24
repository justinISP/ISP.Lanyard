<?php
declare(strict_types=1);

namespace ISP\Lanyard\Service;

use Neos\Flow\Annotations as Flow;

/**
 * Formatiert Cent-Beträge für Mails und Formular.
 *
 * Ganzzahlig gerechnet, ohne Umweg über Fliesskommazahlen.
 */
#[Flow\Scope('singleton')]
class PriceFormatter
{
    #[Flow\InjectConfiguration(path: 'order.currency', package: 'ISP.Lanyard')]
    protected string $currency = 'EUR';

    public function format(int $cents, string $language = 'de'): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        $thousands = $language === 'de' ? '.' : ',';
        $decimal = $language === 'de' ? ',' : '.';

        $euros = number_format(intdiv($cents, 100), 0, '', $thousands);

        return sprintf('%s%s%s%02d %s', $sign, $euros, $decimal, $cents % 100, $this->currency);
    }
}
