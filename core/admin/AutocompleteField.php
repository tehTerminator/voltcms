<?php
declare(strict_types=1);

namespace Cms;

/**
 * Markup for an autocomplete field (see assets/autocomplete.js). Progressive enhancement: the server renders a plain numeric
 * input holding the chosen id (so a form still submits the same field name and value without JavaScript, and validation on the
 * server is unchanged); the script replaces it with a search box and a hidden input.
 */
final class AutocompleteField
{
    /**
     * @param array{id:string,name:string,source:string,value?:mixed,label?:string,thumb?:string,exclude?:?int,placeholder?:string} $o
     *        source: 'content-parent' or 'media-image'; label/thumb describe the currently selected item
     */
    public static function render(array $o): string
    {
        $value = (string) ($o['value'] ?? '');
        $attrs = [
            'data-ac' => '',
            'data-ac-url' => url('/admin/lookup/' . $o['source']),
            'data-ac-label' => (string) ($o['label'] ?? ''),
            'data-ac-thumb' => (string) ($o['thumb'] ?? ''),
            'data-ac-placeholder' => (string) ($o['placeholder'] ?? 'Type to search'),
        ];
        if (($o['exclude'] ?? null) !== null) {
            $attrs['data-ac-exclude'] = (string) (int) $o['exclude'];
        }
        $html = '<div class="ac-wrap"';
        foreach ($attrs as $k => $v) {
            $html .= ' ' . $k . ($v === '' && $k === 'data-ac' ? '' : '="' . e($v) . '"');
        }
        return $html . '><input type="number" class="form-control" id="' . e($o['id']) . '" name="' . e($o['name']) . '" value="' . e($value)
            . '" min="1" inputmode="numeric" data-ac-value></div>';
    }
}
