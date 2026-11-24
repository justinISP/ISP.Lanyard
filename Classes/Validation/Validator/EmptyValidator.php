<?php
declare(strict_types=1);

namespace ISP\Lanyard\Validation\Validator;

use Neos\Flow\Validation\Validator\AbstractValidator;

/**
 * Validator für Honeypot-Felder: gültig ist nur ein leerer Wert.
 *
 * Menschen sehen das Feld nicht (ausgeblendet per CSS im
 * Sitepackage), Bots füllen es aus. Ansprechbar als
 * 'ISP.Lanyard:Empty'.
 *
 * Leere Werte prüft der AbstractValidator gar nicht erst
 * ($acceptsEmptyValues), isValid() läuft also nur für befüllte
 * Felder.
 */
class EmptyValidator extends AbstractValidator
{
    /**
     * @param mixed $value
     */
    protected function isValid($value): void
    {
        $this->addError('This field must be empty.', 1761480001);
    }
}
