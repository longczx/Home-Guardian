<?php
use Eloquent\Migrations\Migrations\Migration;
use app\service\AcProtocolCatalog;

return new class extends Migration {
    private function expand(array $controls): array
    {
        $standard = AcProtocolCatalog::controls();
        foreach ($standard as $control) {
            $found = false;
            foreach ($controls as &$existing) {
                if (($existing['command'] ?? '') === 'set_state' && ($existing['param'] ?? '') === $control['param']) {
                    $existing = array_replace($existing, $control);
                    $found = true;
                    break;
                }
            }
            unset($existing);
            if (!$found) $controls[] = $control;
        }
        return $controls;
    }

    public function up(): void
    {
        foreach ($this->db->table('capability_templates')->where('device_category', 'ac')->get() as $row) {
            $controls = json_decode($row->controls, true, 512, JSON_THROW_ON_ERROR);
            if (!AcProtocolCatalog::isInfrared(['control_mode'=>$row->control_mode, 'controls'=>$controls])) continue;
            $this->db->table('capability_templates')->where('id', $row->id)->update([
                'controls'=>json_encode($this->expand($controls), JSON_UNESCAPED_UNICODE), 'updated_at'=>now(),
            ]);
        }
        foreach ($this->db->table('devices')->whereNotNull('capability')->get() as $row) {
            $cap = json_decode($row->capability, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($cap) || !AcProtocolCatalog::isInfrared($cap)) continue;
            $cap['controls'] = $this->expand($cap['controls']);
            $this->db->table('devices')->where('id', $row->id)->update(['capability'=>json_encode($cap, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        // Additive metadata only. Preserve user-selected protocols and custom
        // capability settings when rolling back application code.
    }
};
