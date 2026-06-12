<?php

namespace App\Data\Portal\Concerns;

use Illuminate\Support\Str;

/**
 * Rendert Markdown-Felder der Portal-API (intro, description) zu HTML.
 * Rohes HTML wird gestrippt und unsichere Links werden entfernt, weil
 * die Inhalte von Portal-Nutzern stammen. Anker werden zu reinem Text,
 * damit Links die WebView nicht ohne Zurück-Navigation verlassen.
 */
trait RendersMarkdown
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'del',
        'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'code', 'pre', 'hr',
    ];

    protected function markdownToHtml(?string $markdown): ?string
    {
        if (blank($markdown)) {
            return null;
        }

        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return strip_tags($html, self::ALLOWED_TAGS);
    }
}
