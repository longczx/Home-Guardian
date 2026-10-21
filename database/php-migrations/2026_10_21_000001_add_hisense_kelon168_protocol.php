<?php
use Eloquent\Migrations\Migrations\Migration;
use app\service\AcIrProtocolCatalog;

return new class extends Migration {
    public function up(): void
    {
        foreach ($this->db()->table('capability_templates')->where('device_category', 'ac')->get() as $template) {
            $controls = json_decode($template->controls ?? '[]', true) ?: [];
            $this->db()->table('capability_templates')->where('id', $template->id)->update([
                'controls'=>json_encode(AcIrProtocolCatalog::upgrade($controls), JSON_UNESCAPED_UNICODE),
            ]);
        }
        foreach ($this->db()->table('devices')->whereNotNull('capability')->get() as $device) {
            $cap = json_decode($device->capability, true);
            if (!is_array($cap) || ($cap['control_mode'] ?? '') !== 'merge' || !is_array($cap['controls'] ?? null)) continue;
            $next = AcIrProtocolCatalog::upgrade($cap['controls']);
            if ($next === $cap['controls']) continue;
            $cap['controls'] = $next;
            $this->db()->table('devices')->where('id', $device->id)->update([
                'capability'=>json_encode($cap, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Retain additive choices on rollback: users may have customized them.
        // No device state, default protocol or command is changed.
    }
};
