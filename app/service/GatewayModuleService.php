<?php
namespace app\service;

use app\exception\BusinessException;
use app\model\Device;

class GatewayModuleService
{
    public static function supports(string $action): bool
    {
        return in_array($action, ['list_modules', 'add_module', 'update_module', 'remove_module'], true);
    }

    public static function validate(Device $device, string $action, array $params): void
    {
        if ($device->type !== 'gateway' || !empty($device->gateway_uid)) {
            throw new BusinessException('模块管理指令只能发送到网关本身', 422, 2120);
        }
        $allowed = match ($action) {
            'list_modules' => [],
            'add_module' => ['type', 'pin', 'name', 'uid'],
            'update_module' => ['uid', 'pin'],
            'remove_module' => ['uid'],
            default => throw new BusinessException('不支持的模块管理指令', 422, 2121),
        };
        if (array_diff(array_keys($params), $allowed)) {
            throw new BusinessException('模块管理参数不合法', 422, 2121);
        }
        if (in_array($action, ['add_module', 'update_module'], true)) {
            if (!isset($params['pin']) || !is_int($params['pin']) || $params['pin'] < 0 || $params['pin'] > 39) {
                throw new BusinessException('GPIO 必须是 0-39 之间的整数，具体可用范围由设备校验', 422, 2121);
            }
        }
        if ($action === 'add_module') {
            if (!in_array($params['type'] ?? null, ['dht11', 'sound', 'ac_ir'], true)) {
                throw new BusinessException('不支持的模块类型', 422, 2121);
            }
            if (isset($params['name']) && (!is_string($params['name']) || strlen($params['name']) > 64)) {
                throw new BusinessException('模块名称过长或格式错误', 422, 2121);
            }
            if (isset($params['uid']) && (!is_string($params['uid']) || !preg_match('/^[A-Za-z0-9_-]{1,47}$/D', $params['uid']))) {
                throw new BusinessException('模块标识格式错误', 422, 2121);
            }
        }
        if (in_array($action, ['update_module', 'remove_module'], true)
            && (!isset($params['uid']) || !is_string($params['uid']) || $params['uid'] === '' || strlen($params['uid']) > 128)) {
            throw new BusinessException('缺少有效模块标识', 422, 2121);
        }
    }
}
