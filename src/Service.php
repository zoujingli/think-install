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

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Util\Platform;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * 组件插件注册
 * @class Service
 * @package think\admin\install
 */
class Service implements PluginInterface
{
    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {

        // 根应用包
        $package = $this->addServer($composer)->getPackage();

        // 注册安装器
        $composer->getInstallationManager()->addInstaller(new Installer($io, $composer));

        // 读取根应用配置
        $config = json_decode(file_get_contents('composer.json'), true);
        if (empty($config['type']) && empty($config['name'])) {
            method_exists($package, 'setType') && $package->setType('project');
        }

        // 读取项目根参数
        if ($package->getType() === 'project') {

            // 修改项目配置
//          if (empty($pluginCenter)) {
//              $composer->getConfig()->getConfigSource()->addRepository('plugins', [
//                  'url' => $pluginUrl, 'type' => 'composer', 'canonical' => false
//              ]);
//          }

            // 注册自动加载
            $auto = $package->getAutoload();
            if (empty($auto)) $package->setAutoload([
                'psr-0' => ['' => 'extend'], 'psr-4' => ['app\\' => 'app'],
            ]);

            // 写入环境路径
            $this->putServer();

            // 注册自动安装脚本
            $dispatcher = $composer->getEventDispatcher();
            $dispatcher->addListener('post-autoload-dump', function () use ($dispatcher) {

                // 初始化服务配置
                $services = file_exists($file = 'vendor/services.php') ? (array)include($file) : [];
                if (!in_array($service = 'think\\admin\\Library', $services)) {
                    $services = array_unique(array_merge($services, ['think\\migration\\Service', $service]));
                    @file_put_contents($file, '<?php' . PHP_EOL . 'return ' . var_export($services, true) . ';');
                }

                // 执行应用模块安装指令
                $dispatcher->addListener('PluginScript', '@php think xadmin:publish --migrate');
                $dispatcher->dispatch('PluginScript');

            });
        }
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * 增加插件服务 ( 需上报应用标识信息 )
     * @param \Composer\Composer $composer
     * @return \Composer\Composer
     */
    private function addServer(Composer $composer): Composer
    {
//      暂时不使用第三方库安装
//      $manager = $composer->getRepositoryManager();
//      $manager->prependRepository($manager->createRepository('composer', [
//          'url'     => Support::getServer() . 'packages.json?type=json', 'canonical' => false,
//          'options' => ['http' => ['header' => ["Authorization: Bearer {$this->buildAuthToken()}"]]],
//      ]));
        return $composer;
    }

    /**
     * 写入环境路径
     */
    private function putServer()
    {
        // 预注册系统
        if (!file_exists('vendor/binarys.php')) {
            @fopen(Support::getServer() . 'packages.json?type=notify', 'r', false, stream_context_create([
                'http' => ['header' => ["Authorization: Bearer {$this->buildAuthToken()}"], 'timeout' => 3]
            ]));
        }
        // 写入环境变量
        $export = var_export([
            'cpu' => Support::getCpuId(), 'mac' => Support::getMacId(),
            'uni' => Support::getSysId(), 'php' => (new PhpExecutableFinder())->find(false) ?: 'php',
            'com' => getenv('COMPOSER_BINARY') ?: (Platform::getEnv('COMPOSER_BINARY') ?: 'composer'),
        ], true);
        $header = '// Automatically Generated At: ' . date('Y-m-d H:i:s') . PHP_EOL . 'declare(strict_types=1);';
        @file_put_contents('vendor/binarys.php', '<?php' . PHP_EOL . $header . PHP_EOL . "return {$export};");
    }

    /**
     * 获取认证令牌
     * @return string
     */
    private function buildAuthToken(): string
    {
        return base64_encode(json_encode([
            Support::getCpuId(), Support::getMacId(), Support::getSysId(), Support::getUname()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'unknow');
    }
}