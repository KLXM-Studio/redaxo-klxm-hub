<?php

declare(strict_types=1);

namespace Klxm\Hub;

use Klxm\Hub\Oidc\Client;
use Redaxo\Core\Filesystem\Path;

/**
 * Logo des Anbieters für den Anmelde-Link: Discovery-Feld `logo_uri` (nicht im OIDC-Standard, optional, nur https),
 * einmal geladen, als SVG streng bereinigt (nur Formen, keine Skripte, Stile, Verweise oder Ereignisse) und in
 * var/data/addons/klxm_hub/ abgelegt – die Anmeldeseite lädt nie etwas vom Anbieter nach. Ohne Logo: null.
 */
final class Logo
{
    private const MAX = 65536;
    private const TAGS = ['svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'title'];
    private const ATTRS = ['viewbox', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points',
        'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'opacity', 'fill-opacity', 'stroke-opacity', 'transform', 'xmlns'];

    /** Inline-SVG (dekorativ) oder null – liest nur die lokale Kopie (nie ein Abruf beim Anzeigen der Anmeldeseite) */
    public static function svg(): ?string
    {
        $file = self::file((string) (Settings::oidc()['issuer'] ?? ''));
        if (null === $file || !is_file($file)) {
            return null;
        }
        $svg = (string) file_get_contents($file);

        return '' !== $svg ? $svg : null;
    }

    /** Logo neu laden und ablegen (beim Speichern der Einstellungen bzw. Prüfen der Discovery); true = Logo vorhanden */
    public static function refresh(string $issuer): bool
    {
        $file = self::file($issuer);
        if (null === $file) {
            return false;
        }
        $svg = self::load($issuer) ?? '';
        @mkdir(\dirname($file), 0o775, true);
        @file_put_contents($file, $svg, LOCK_EX);

        return '' !== $svg;
    }

    private static function file(string $issuer): ?string
    {
        return '' === $issuer ? null : Path::addonData(HubAddon::NAME, 'logo-' . substr(hash('sha256', $issuer), 0, 16) . '.svg');
    }

    private static function load(string $issuer): ?string
    {
        try {
            $uri = (string) (Client::discovery($issuer)['logo_uri'] ?? '');
            if (!preg_match('~^https://[^\s]+$~', $uri)) {
                return null;
            }
            $r = Http::request('GET', $uri, ['Accept' => 'image/svg+xml']);
        } catch (\Throwable) {
            return null;   // Anbieter nicht erreichbar: Link ohne Logo, die Anmeldung selbst ist davon nicht betroffen
        }
        if (200 !== $r['status'] || \strlen($r['body']) > self::MAX) {
            return null;
        }

        return self::clean($r['body']);
    }

    /** Nur erlaubte Elemente und Attribute; Farben ohne url(…). */
    public static function clean(string $svg): ?string
    {
        if (!str_contains($svg, '<svg') || preg_match('~<!DOCTYPE|<!ENTITY~i', $svg)) {
            return null;
        }
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($svg, LIBXML_NONET)) {
            return null;
        }
        $root = $doc->documentElement;
        if (!$root || 'svg' !== strtolower($root->localName)) {
            return null;
        }
        $walk = static function (\DOMElement $el) use (&$walk): void {
            foreach (iterator_to_array($el->childNodes) as $c) {
                if ($c instanceof \DOMElement) {
                    if (!\in_array(strtolower($c->localName), self::TAGS, true)) {
                        $el->removeChild($c);
                        continue;
                    }
                    $walk($c);
                } elseif (!$c instanceof \DOMText) {
                    $el->removeChild($c);
                }
            }
            foreach (iterator_to_array($el->attributes) as $a) {
                $n = strtolower($a->nodeName);
                if (!\in_array($n, self::ATTRS, true) || preg_match('~url\(|javascript:|expression\(~i', $a->nodeValue ?? '')) {
                    $el->removeAttribute($a->nodeName);
                }
            }
        };
        $walk($root);
        foreach (iterator_to_array($root->getElementsByTagName('title')) as $t) {
            $t->parentNode?->removeChild($t);   // dekorativ – der Name steht im Text daneben
        }
        $root->setAttribute('class', 'klxm-hub-login-logo');
        $root->setAttribute('aria-hidden', 'true');
        $root->setAttribute('focusable', 'false');
        $out = $doc->saveXML($root);

        return false !== $out && \strlen($out) <= self::MAX ? $out : null;
    }
}
