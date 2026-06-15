<?php

namespace App\Http\Integrations\Portal\Requests;

use RuntimeException;
use Saloon\Contracts\Body\HasBody;
use Saloon\Data\MultipartValue;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasMultipartBody;

/**
 * Gemeinsame Basis für die multipart-Bild-Uploads (Meetup-Logo, Referenten-
 * Avatar, Kurs-Logo). Anders als die JSON-Writes ({@see PortalWriteRequest})
 * trägt sie einen `multipart/form-data`-Body mit genau einem Datei-Feld `file`
 * — exakt das Feld, das der gemeinsame Portal-`UploadMediaRequest` erwartet
 * (`image|mimes:jpeg,png,webp,avif|max:5120|dimensions:max 4000x4000`).
 *
 * Der Upload läuft zweistufig: die App legt die Stammdaten zuerst per JSON an
 * (bekommt die ID zurück) und lädt das Bild danach an `…/{id}/logo|avatar` hoch.
 * Die konkrete Route liefert {@see resolveEndpoint()} der Unterklassen.
 */
abstract class UploadMediaRequest extends Request implements HasBody
{
    use HasMultipartBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected readonly int $id,
        protected readonly string $filePath,
    ) {}

    /**
     * @return array<MultipartValue>
     */
    protected function defaultBody(): array
    {
        $stream = fopen($this->filePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Bild-Datei konnte nicht geöffnet werden: {$this->filePath}");
        }

        return [
            new MultipartValue(
                name: 'file',
                value: $stream,
                filename: basename($this->filePath),
            ),
        ];
    }
}
