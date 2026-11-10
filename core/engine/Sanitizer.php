<?php
declare(strict_types=1);

namespace Cms;

/**
 * Allowlist HTML sanitizer for content bodies (applied when content is saved).
 *
 * Policy (also documented in ARCHITECTURE.md):
 *  - Normal publishing markup is kept: headings, text, lists, tables, links, images, figures, details,
 *    Bootstrap layout and component markup (class, id, role, aria-*, data-* attributes).
 *  - Removed with their contents: script, style, iframe, object, embed, svg, math, form controls, media, link/meta/base.
 *  - Unknown but harmless elements are unwrapped (children kept). Comments are dropped.
 *  - All on* event attributes are dropped. URLs must be http, https, mailto, tel, a fragment or a relative path;
 *    images may also use data:image/(png|jpeg|gif|webp). External links with target=_blank get rel="noopener noreferrer".
 *  - style="" is kept only for a short allowlist of properties; values with url(), expressions or odd characters are dropped.
 * Limits: this is not a substitute for trusting the administrator. Files edited on disk are not re-sanitized.
 */
final class Sanitizer
{
    private const REMOVE = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template',
        'svg', 'math', 'link', 'meta', 'base', 'title', 'head', 'form', 'input', 'select', 'textarea',
        'audio', 'video', 'source', 'track', 'canvas', 'portal',
    ];

    private const ALLOWED = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'blockquote', 'br', 'button', 'caption', 'cite', 'code',
        'col', 'colgroup', 'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'main', 'mark',
        'nav', 'ol', 'p', 'pre', 'q', 's', 'samp', 'section', 'small', 'span', 'strong', 'sub', 'summary', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul', 'var', 'wbr',
    ];

    private const GLOBAL_ATTRS = ['class', 'id', 'title', 'lang', 'dir', 'role', 'style', 'tabindex'];

    private const TAG_ATTRS = [
        'a' => ['href', 'target', 'rel', 'hreflang'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
        'td' => ['colspan', 'rowspan', 'headers'],
        'th' => ['colspan', 'rowspan', 'scope', 'headers', 'abbr'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'ol' => ['start', 'type', 'reversed'],
        'li' => ['value'],
        'time' => ['datetime'],
        'blockquote' => ['cite'],
        'q' => ['cite'],
        'del' => ['cite', 'datetime'],
        'ins' => ['cite', 'datetime'],
        'button' => ['type', 'disabled'],
        'details' => ['open'],
    ];

    private const URL_ATTRS = ['href', 'src', 'cite'];

    private const STYLE_PROPS = [
        'color', 'background-color', 'background', 'font-size', 'font-weight', 'font-style', 'font-family',
        'text-align', 'text-decoration', 'text-transform', 'text-indent', 'line-height', 'letter-spacing',
        'word-spacing', 'word-break', 'white-space', 'vertical-align', 'width', 'max-width', 'min-width',
        'height', 'max-height', 'min-height', 'display', 'float', 'clear', 'opacity', 'overflow',
        'list-style', 'list-style-type', 'margin', 'padding', 'border', 'border-radius', 'border-collapse',
        'border-spacing', 'table-layout',
    ];
    private const STYLE_PREFIXES = ['margin-', 'padding-', 'border-'];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><html><body><div>' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }
        $this->walk($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return trim($out);
    }

    private function walk(\DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $parent->removeChild($child); // comments, processing instructions
                continue;
            }
            /** @var \DOMElement $child */
            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::REMOVE, true)) {
                $parent->removeChild($child);
                continue;
            }
            $this->walk($child);
            if (!in_array($tag, self::ALLOWED, true)) {
                while ($child->firstChild !== null) { // unwrap: keep the (already cleaned) children
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            $this->cleanAttributes($child, $tag);
        }
    }

    private function cleanAttributes(\DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::TAG_ATTRS[$tag] ?? []);
        $names = [];
        foreach ($el->attributes as $attr) {
            $names[] = $attr->nodeName;
        }
        foreach ($names as $name) {
            $lower = strtolower($name);
            $value = (string) $el->getAttribute($name);
            $ok = !str_starts_with($lower, 'on')
                && (in_array($lower, $allowed, true) || str_starts_with($lower, 'aria-') || str_starts_with($lower, 'data-'));
            if ($ok && in_array($lower, self::URL_ATTRS, true)) {
                $ok = $this->safeUrl($value, $tag === 'img' && $lower === 'src');
            }
            if ($ok && $lower === 'style') {
                $value = $this->cleanStyle($value);
                $ok = $value !== '';
            }
            if ($ok && $tag === 'a' && $lower === 'target') {
                $ok = strtolower($value) === '_blank';
            }
            if (!$ok) {
                $el->removeAttribute($name);
            } elseif ($lower === 'style') {
                $el->setAttribute($name, $value);
            }
        }
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'button') {
            $el->setAttribute('type', 'button'); // never a submit button
        }
    }

    private function safeUrl(string $value, bool $allowDataImage): bool
    {
        $compact = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $value); // "java\tscript:" tricks
        if ($compact === '') {
            return true;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $compact, $m) !== 1) {
            return true; // relative URL, fragment or query
        }
        $scheme = strtolower($m[1]);
        if (in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            return true;
        }
        return $allowDataImage && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $compact) === 1;
    }

    private function cleanStyle(string $style): string
    {
        if (strlen($style) > 600) {
            return '';
        }
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $prop = strtolower(trim($parts[0]));
            $val = trim($parts[1]);
            if ($val === '' || !$this->propAllowed($prop)) {
                continue;
            }
            if (preg_match('/url\s*\(|expression|javascript|vbscript|behavior|binding|@|\\\\|<|>|\/\*/i', $val) === 1) {
                continue;
            }
            if (preg_match('/^[\pL\pN\s#%.,()\-+\/"\'!]*$/u', $val) !== 1) {
                continue;
            }
            $kept[] = $prop . ': ' . $val;
        }
        return implode('; ', $kept);
    }

    private function propAllowed(string $prop): bool
    {
        if (in_array($prop, self::STYLE_PROPS, true)) {
            return true;
        }
        foreach (self::STYLE_PREFIXES as $prefix) {
            if (str_starts_with($prop, $prefix) && preg_match('/^[a-z\-]+$/', $prop) === 1) {
                return true;
            }
        }
        return false;
    }
}
