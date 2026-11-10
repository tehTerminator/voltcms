<?php
declare(strict_types=1);

namespace Cms;

/** The public-facing site settings in one array (the `$site` variable in themes). */
final class SiteInfo
{
    /**
     * @return array{name:string,lang:string,url:string,description:string,footer:string,logo:string,logo_alt:string,social_image:string,social_image_path:string}
     */
    public static function load(Settings $settings, MediaRepository $media): array
    {
        $logoId = (int) $settings->get('site_logo_media_id', '0');
        $socialId = (int) $settings->get('site_social_media_id', '0');
        $rows = $media->findMany([$logoId, $socialId]);
        $url = rtrim((string) $settings->get('site_url', ''), '/');
        return [
            'name' => (string) $settings->get('site_name', 'Site'),
            'lang' => (string) $settings->get('site_lang', 'en'),
            'url' => preg_match('#^https?://[^\s/]+#i', $url) === 1 ? $url : '',
            'description' => (string) $settings->get('site_description', ''),
            'footer' => (string) $settings->get('footer_text', ''),
            'logo' => isset($rows[$logoId]) ? MediaRepository::url($rows[$logoId]) : '',
            'logo_alt' => isset($rows[$logoId]) ? (string) ($rows[$logoId]['alt_text'] ?? '') : '',
            'social_image' => isset($rows[$socialId]) ? MediaRepository::url($rows[$socialId]) : '',
            'social_image_path' => isset($rows[$socialId]) ? (string) $rows[$socialId]['path'] : '',
        ];
    }
}
