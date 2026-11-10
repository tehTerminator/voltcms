<?php
declare(strict_types=1);

namespace Cms;

/** Key/value site settings stored in the settings table. */
final class Settings
{
    public function __construct(private Database $db)
    {
    }

    private ?array $cache = null;

    /** @return array<string,string> every setting, loaded once per request */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->all('SELECT setting_key, setting_value FROM {p}settings') as $r) {
                $this->cache[$r['setting_key']] = (string) $r['setting_value'];
            }
        }
        return $this->cache;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO {p}settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
            [$key, $value, gmdate('Y-m-d H:i:s')]
        );
        $this->cache = null;
    }
}
