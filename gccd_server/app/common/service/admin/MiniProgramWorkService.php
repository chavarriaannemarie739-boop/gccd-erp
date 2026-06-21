<?php

namespace app\common\service\admin;

use app\common\config\ProductionConfig;
use think\facade\Db;

/**
 * 小程序报工：用户工序上下文
 */
class MiniProgramWorkService
{
    /** @var string[] */
    private static $processNames = ['断钢', '穿孔', '球化', '矫直', '酸洗'];

    /**
     * 根据用户部门/岗位推断主工序
     *
     * @return array{processes_code:string,processes_name:string,entry_type:string}
     */
    public static function resolveWorkContext(int $userId): array
    {
        $user = Db::name('system_user')->where('user_id', $userId)->where('is_delete', 0)->find();
        if (empty($user)) {
            return self::emptyContext();
        }

        $deptIds = Db::name('system_user_attributes')
            ->where('user_id', $userId)
            ->column('dept_id');
        $deptNames = [];
        if ($deptIds !== []) {
            $deptNames = Db::name('system_dept')
                ->whereIn('dept_id', $deptIds)
                ->column('dept_name');
        }

        $haystack = implode(' ', $deptNames) . ' ' . (string) ($user['nickname'] ?? '');

        foreach (self::$processNames as $name) {
            if ($name !== '' && mb_strpos($haystack, $name) !== false) {
                $code = RoutingProcessHelper::getProcessCodeByName($name);
                if ($code !== '') {
                    return [
                        'processes_code' => $code,
                        'processes_name' => $name,
                        'entry_type' => self::entryTypeForProcess($name),
                    ];
                }
            }
        }

        return self::emptyContext();
    }

    private static function emptyContext(): array
    {
        return [
            'processes_code' => '',
            'processes_name' => '',
            'entry_type' => 'generic',
        ];
    }

    private static function entryTypeForProcess(string $name): string
    {
        if ($name === ProductionConfig::PROCESS_NAME_DUANGANG) {
            return 'duangang_merge';
        }
        if ($name === ProductionConfig::PROCESS_NAME_PUNCH) {
            return 'punch_device';
        }

        return 'process_task';
    }
}
