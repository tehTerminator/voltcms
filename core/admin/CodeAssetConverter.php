<?php
declare(strict_types=1);

namespace Cms;

/**
 * One-time conversion (migration 0007): per-page custom CSS/JavaScript from the Phase 2/3 columns content.custom_css and
 * content.custom_js becomes code assets linked to the page. Old columns are left untouched as a safety copy.
 * A value that fails the new validation is skipped and logged, so nothing is ever lost.
 */
final class CodeAssetConverter
{
    /** @return array{converted:int,skipped:int} */
    public function run(Database $db, CodeAssetStore $store): array
    {
        $out = ['converted' => 0, 'skipped' => 0];
        if ($db->one("SHOW COLUMNS FROM {p}content LIKE 'custom_css'") === null) {
            return $out;
        }
        $writer = new CodeAssetWriter($db, $store, new CodeAssetRepository($db));
        $rows = $db->all(
            "SELECT id, title, custom_css, custom_js FROM {p}content
             WHERE (custom_css IS NOT NULL AND custom_css <> '') OR (custom_js IS NOT NULL AND custom_js <> '') ORDER BY id"
        );
        foreach ($rows as $row) {
            foreach (['css' => 'custom_css', 'js' => 'custom_js'] as $kind => $column) {
                $code = (string) $row[$column];
                if (trim($code) === '') {
                    continue;
                }
                $name = 'Imported: ' . mb_substr((string) $row['title'], 0, 55) . ' (' . $kind . ') #' . $row['id'];
                $note = 'Moved from the page "' . mb_substr((string) $row['title'], 0, 100) . '" when upgrading to 0.4.0.';
                [$errors, $clean] = $writer->validate($kind, $name, $note, '100', $code, null);
                if ($errors !== []) {
                    Logger::error('Custom ' . $kind . ' of content ' . $row['id'] . ' was not converted: ' . implode(' ', $errors));
                    $out['skipped']++;
                    continue;
                }
                $assetId = $writer->create($clean);
                $db->run('INSERT IGNORE INTO {p}content_assets (content_id, asset_id) VALUES (?, ?)', [(int) $row['id'], $assetId]);
                $out['converted']++;
            }
        }
        return $out;
    }
}
