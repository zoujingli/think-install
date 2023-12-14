<?php

// +----------------------------------------------------------------------
// | Plugin Installer for ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2023 Anyon <zoujingli@qq.com>
// +----------------------------------------------------------------------
// | 官方网站: https://thinkadmin.top
// +----------------------------------------------------------------------
// | 免费声明 ( https://thinkadmin.top/disclaimer )
// | 开源协议 ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | gitee 代码仓库：https://gitee.com/zoujingli/think-install
// | github 代码仓库：https://github.com/zoujingli/think-install
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace think\admin\install;

use Symfony\Component\Process\Process;

/**
 * 插件基础支持
 * @class Support
 * @package think\admin\install
 */
abstract class Support
{
    /**
     * 获取服务地址
     * @return string
     */
    public static function getServer(): string
    {
        return base64_decode('aHR0cHM6Ly9wbHVnaW4udGhpbmthZG1pbi50b3Av');
    }

    /**
     * 获取系统序号
     * @return string
     */
    public static function getSysId(): string
    {
        static $sysid;
        if ($sysid) return $sysid;
        [$cpuid, $macid, $root] = ['', '', dirname(__DIR__, 4)];
        if (file_exists($file = "{$root}/vendor/binarys.php")) {
            if (($info = include $file) && is_array($info)) {
                [$cpuid, $macid] = [$info['cpu'] ?? '', $info['mac'] ?? ''];
            }
        }
        $cpuid = $cpuid ?: static::getCpuId();
        $macid = $macid ?: static::getMacId();
        return $sysid = strtoupper(md5("{$macid}#{$cpuid}#{$root}"));
    }

    /**
     * 获取处理器序号
     * @return string
     */
    public static function getCpuId(): string
    {
        static $cpuid;
        if ($cpuid) return $cpuid;
        $command = self::isWin() ? 'wmic cpu get ProcessorID' : 'dmidecode -t processor | grep ID';
        self::exec($command, static function ($type, $line) use (&$cpuid) {
            if (preg_match('|[0-9A-F]{16}|', $line, $match)) {
                return !!($cpuid = strtoupper($match[0]));
            }
            return false;
        });
        if (empty($cpuid)) {
            $tmpfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.thinkadmin.cpuid';
            if (!is_file($tmpfile) || !($cpuid = file_get_contents($tmpfile))) {
                $cpuid = strtoupper(substr(md5(uniqid(strval(rand(1, 100)))), -16));
                @file_put_contents($tmpfile, $cpuid);
            }
        }
        return $cpuid;
    }

    /**
     * 获取MAC地址
     * @return string
     */
    public static function getMacId(): string
    {
        static $macid;
        if ($macid) return $macid;
        self::exec(self::isWin() ? 'ipconfig /all' : 'ifconfig -a', static function ($type, $line) use (&$macid) {
            if (preg_match("#((00|FF)[:-]){5}(00|FF)#i", $line)) return false;
            if (preg_match('#([0-9A-F]{2}[:-]){5}[0-9A-F]{2}#', $line, $match)) {
                return !!($macid = $match[0]);
            } else {
                return false;
            }
        });
        if (empty($macid)) {
            $tmpfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.thinkadmin.macid';
            if (!is_file($tmpfile) || !($macid = file_get_contents($tmpfile))) {
                @file_put_contents($tmpfile, $macid = static::randMacAddress());
            }
        }
        return $macid = strtoupper(strtr($macid, ':', '-'));
    }

    /**
     * 获取系统信息
     * @return string
     */
    public static function getUname(): string
    {
        return self::text2utf8(php_uname());
    }

    /**
     * 判断运行环境
     * @return boolean
     */
    public static function isWin(): bool
    {
        return PATH_SEPARATOR === ';';
    }

    /**
     * 执行回调处理
     * @param string $command
     * @param ?callable $callabel
     * @return string
     */
    public static function exec(string $command, callable $callabel = null): string
    {
        if (method_exists(Process::class, 'fromShellCommandline')) {
            $process = Process::fromShellCommandline($command);
        } else {
            $process = new Process([]);
            if (method_exists($process, 'setCommandLine')) {
                $process->setCommandLine($command);
            }
        }
        $process->run(static function ($type, $line) use ($process, $callabel) {
            if (is_callable($callabel) && $callabel($type, self::text2utf8($line)) === true) {
                $process->stop();
            }
        });
        return self::text2utf8($process->getOutput());
    }

    /**
     * 生成随机MAC地址
     * @return string
     */
    private static function randMacAddress(): string
    {
        $attr = [
            mt_rand(0x00, 0x7f), mt_rand(0x00, 0x7f), mt_rand(0x00, 0x7f),
            mt_rand(0x00, 0x7f), mt_rand(0x00, 0xff), mt_rand(0x00, 0xff)
        ];
        return join('-', array_map(function ($v) {
            return sprintf('%02X', $v);
        }, $attr));
    }

    /**
     * 文本内容转码
     * @param string $text 文本内容
     * @return string
     */
    private static function text2utf8(string $text): string
    {
        [$first2, $first4] = [substr($text, 0, 2), substr($text, 0, 4)];
        if ($first4 === chr(0x00) . chr(0x00) . chr(0xFE) . chr(0xFF)) $ft = 'UTF-32BE';
        elseif ($first4 === chr(0xFF) . chr(0xFE) . chr(0x00) . chr(0x00)) $ft = 'UTF-32LE';
        elseif ($first2 === chr(0xFF) . chr(0xFE)) $ft = 'UTF-16LE';
        elseif ($first2 === chr(0xFE) . chr(0xFF)) $ft = 'UTF-16BE';
        try {
            return mb_convert_encoding($text, 'UTF-8', $ft ?? mb_detect_encoding($text));
        } catch (\Exception|\Error $exception) {
            return $text;
        }
    }
}