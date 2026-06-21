<?php

namespace app\common\service\admin;

use think\facade\Db;

/**
 * 工艺路线工序辅助
 */
class RoutingProcessHelper
{
    public static function routingHasProcessName(string $routingCode, string $processName): bool
    {
        if ($routingCode === '' || $processName === '') {
            return false;
        }
        $rows = Db::name('routing_detail')
            ->alias('rd')
            ->leftJoin('processes p', 'p.processes_code = rd.process_code')
            ->where('rd.routing_code', $routingCode)
            ->where('rd.is_delete', 0)
            ->field('p.processes_name')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if ((string) ($row['processes_name'] ?? '') === $processName) {
                return true;
            }
        }

        return false;
    }

    public static function getProcessCodeByName(string $processName): string
    {
        $row = Db::name('processes')
            ->where('processes_name', $processName)
            ->where('is_delete', 0)
            ->find();

        return $row ? (string) ($row['processes_code'] ?? '') : '';
    }

    /**
     * 下一道工序编码（按 sort）
     */
    public static function getNextProcessCode(string $routingCode, string $currentProcessCode): string
    {
        $list = Db::name('routing_detail')
            ->where('routing_code', $routingCode)
            ->where('is_delete', 0)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        $found = false;
        foreach ($list as $row) {
            $code = (string) ($row['process_code'] ?? '');
            if ($found && $code !== '') {
                return $code;
            }
            if ($code === $currentProcessCode) {
                $found = true;
            }
        }

        return '';
    }
}
