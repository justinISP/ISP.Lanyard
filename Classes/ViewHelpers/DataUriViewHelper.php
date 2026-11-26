<?php
declare(strict_types=1);

namespace ISP\Lanyard\ViewHelpers;

use Neos\FluidAdaptor\Core\ViewHelper\AbstractViewHelper;
use Neos\Utility\MediaTypes;

/**
 * Bettet eine Datei als data-URI ein, z. B. ein Logo oder eine
 * Schrift im Ticket-PDF. dompdf lädt nichts aus dem Netz, und Mails
 * brauchen keine externe Adresse.
 *
 *   {namespace lanyard=ISP\Lanyard\ViewHelpers}
 *   <img src="{lanyard:dataUri(path: 'resource://ISP.KegelWM/Public/Images/logo.png')}" />
 */
class DataUriViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * @var array<string, string>
     */
    protected static array $cache = [];

    public function initializeArguments(): void
    {
        $this->registerArgument('path', 'string', 'Pfad, auch resource://Paket/…', true);
    }

    public function render(): string
    {
        $path = (string)$this->arguments['path'];

        if (!isset(self::$cache[$path])) {
            $content = @file_get_contents($path);

            if ($content === false) {
                return '';
            }

            self::$cache[$path] = 'data:' . MediaTypes::getMediaTypeFromFilename($path) . ';base64,' . base64_encode($content);
        }

        return self::$cache[$path];
    }
}
