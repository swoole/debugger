<?php

namespace Swoole\Debugger;

use Swoole\Coroutine;
use Swoole\Timer;
use Exception;
use Throwable;

class RemoteShell
{
    const STX = "DEBUG";

    private static $contexts = array();

    static $oriPipeMessageCallback = null;

    /**
     * @var \swoole\server
     */
    static $serv;

    static $menu = array(
        "p|print [variable]\t打印一个PHP变量的值",
        "e|exec [code]\t执行一段PHP代码",
        "w|worker [id]\t切换Worker进程",
        "l|list\t打印服务器所有连接的fd",
        "s|stats\t打印服务器状态",
        "c|coros\t打印协程列表",
        "cs|costats\t打印协程状态",
        "el|elapsed [cid]\t打印某个协程运行的时间",
        "tl|timer_list\t打印当前进程中所有定时器ID",
        "ti|timer_info [timer_id]\t打印某个定时器信息",
        "ts|timer_stats\t打印当前进程中的定时器状态",
        "b|bt [cid]\t打印某个协程调用栈",
        "i|info [fd]\t显示某个连接的信息",
        "h|help\t显示帮助界面",
        "q|quit\t退出终端",
    );

    const PAGESIZE = 20;

    /**
     * @param $serv \swoole\server
     * @param string $host
     * @param int $port
     * @throws Exception
     */
    static function listen($serv, $host = "127.0.0.1", $port = 9599)
    {
        $port = $serv->listen($host, $port, SWOOLE_SOCK_TCP);
        if (!$port) {
            throw new Exception("listen fail.");
        }
        $port->set(
            array(
                "open_eof_split" => true,
                'package_eof' => "\r\n",
            )
        );
        $port->on("Connect", array(__CLASS__, 'onConnect'));
        $port->on("Close", array(__CLASS__, 'onClose'));
        $port->on("Receive", array(__CLASS__, 'onReceive'));

        if (method_exists($serv, 'getCallback')) {
            self::$oriPipeMessageCallback = $serv->getCallback('PipeMessage');
        }

        $serv->on("PipeMessage", array(__CLASS__, 'onPipeMessage'));
        self::$serv = $serv;
    }

    static function onConnect($serv, $fd, $reactor_id)
    {
        self::$contexts[$fd]['worker_id'] = $serv->worker_id;
        self::output($fd, implode("\r\n", self::$menu));
    }

    static function output($fd, $msg)
    {
        if (!isset(self::$contexts[$fd]['worker_id'])) {
            $msg .= "\r\nworker#" . self::$serv->worker_id . "$ ";
        } else {
            $msg .= "\r\nworker#" . self::$contexts[$fd]['worker_id'] . "$ ";
        }
        self::$serv->send($fd, $msg);
    }

    static function onClose($serv, $fd, $reactor_id)
    {
        unset(self::$contexts[$fd]);
    }

    static function onPipeMessage($serv, $src_worker_id, $message)
    {
        //不是 debug 消息
        if (!is_string($message) or substr($message, 0, strlen(self::STX)) != self::STX) {
            if (self::$oriPipeMessageCallback == null) {
                trigger_error("require swoole-4.3.0 or later.", E_USER_WARNING);
                return;
            }
            return call_user_func(self::$oriPipeMessageCallback, $serv, $src_worker_id, $message);
        } else {
            $request = unserialize(substr($message, strlen(self::STX)));
            self::call($request['fd'], $request['func'], $request['args']);
        }
    }

    static protected function call($fd, $func, $args)
    {
        ob_start();
        call_user_func_array($func, $args);
        self::output($fd, ob_get_clean());
    }

    static protected function exec($fd, $func, $args)
    {
        try {
            //不在当前Worker进程
            if (self::$contexts[$fd]['worker_id'] != self::$serv->worker_id) {
                self::$serv->sendMessage(
                    self::STX . serialize(['fd' => $fd, 'func' => $func, 'args' => $args]),
                    self::$contexts[$fd]['worker_id']
                );
            } else {
                self::call($fd, $func, $args);
            }
        } catch (Throwable $e) {
            self::output($fd, $e->getMessage());
        }
    }

    static function getCoros()
    {
        var_export(iterator_to_array(Coroutine::listCoroutines()));
    }

    static function getCoStats()
    {
        var_export(Coroutine::stats());
    }

    static function getCoElapsed($cid)
    {
        if (!defined('SWOOLE_VERSION_ID') || SWOOLE_VERSION_ID < 40500) {
            trigger_error("require swoole-4.5.0 or later.", E_USER_WARNING);
            return;
        }
        var_export(Coroutine::getElapsed($cid));
    }

    static function getTimerList()
    {
        if (!defined('SWOOLE_VERSION_ID') || SWOOLE_VERSION_ID < 40400) {
            trigger_error("require swoole-4.4.0 or later.", E_USER_WARNING);
            return;
        }
        var_export(iterator_to_array(Timer::list()));
    }

    static function getTimerInfo($timer_id)
    {
        if (!defined('SWOOLE_VERSION_ID') || SWOOLE_VERSION_ID < 40400) {
            trigger_error("require swoole-4.4.0 or later.", E_USER_WARNING);
            return;
        }
        var_export(Timer::info($timer_id));
    }

    static function getTimerStats()
    {
        if (!defined('SWOOLE_VERSION_ID') || SWOOLE_VERSION_ID < 40400) {
            trigger_error("require swoole-4.4.0 or later.", E_USER_WARNING);
            return;
        }
        var_export(Timer::stats());
    }

    static function getBackTrace($_cid)
    {
        $info = Coroutine::getBackTrace($_cid);
        if (!$info) {
            echo "coroutine $_cid not found.";
        } else {
            echo get_debug_print_backtrace($info);
        }
    }

    static function printVariable($var)
    {
        $var = ltrim($var, '$ ');
        var_dump($var);
        var_dump($$var);
    }

    static function evalCode($code)
    {
        eval($code . ';');
    }

    /**
     * @param $serv \swoole\server
     * @param $fd
     * @param $reactor_id
     * @param $data
     */
    static function onReceive($serv, $fd, $reactor_id, $data)
    {
        $args = explode(" ", ltrim($data), 2);
        $cmd = trim($args[0]);
        unset($args[0]);
        if ($cmd === '') {
            self::output($fd, $cmd);
            return;
        }
        switch ($cmd) {
            case 'w':
            case 'worker':
                if (!isset($args[1])) {
                    self::output($fd, "Missing worker id.");
                    break;
                }
                $dstWorkerId = intval($args[1]);
                self::$contexts[$fd]['worker_id'] = $dstWorkerId;
                self::output($fd, "[switching to worker " . self::$contexts[$fd]['worker_id'] . "]");
                break;
            case 'e':
            case 'exec':
                if (!isset($args[1])) {
                    self::output($fd, "Missing code.");
                    break;
                }
                $var = trim($args[1]);
                self::exec($fd, 'self::evalCode', [$var]);
                break;
            case 'p':
            case 'print':
                if (!isset($args[1])) {
                    self::output($fd, "Missing variable.");
                    break;
                }
                $var = trim($args[1]);
                self::exec($fd, 'self::printVariable', [$var]);
                break;
            case 'h':
            case 'help':
                self::output($fd, implode("\r\n", self::$menu));
                break;
            case 's':
            case 'stats':
                $stats = $serv->stats();
                self::output($fd, var_export($stats, true));
                break;
            case 'c':
            case 'coros':
                self::exec($fd, 'self::getCoros', []);
                break;
            /**
             * 获取协程状态
             * @link https://wiki.swoole.com/#/coroutine/coroutine?id=stats
             */
            case 'cs':
            case 'costats':
                self::exec($fd, 'self::getCoStats', []);
                break;
            /**
             * 获取协程运行的时间
             * @link https://wiki.swoole.com/#/coroutine/coroutine?id=getelapsed
             */
            case 'el':
            case 'elapsed':
                $cid = 0;
                if (isset($args[1])) {
                    $cid = intval($args[1]);
                }
                self::exec($fd, 'self::getCoElapsed', [$cid]);
                break;
            /**
             * 查看协程堆栈
             * @link https://wiki.swoole.com/#/coroutine/coroutine?id=getbacktrace
             */
            case 'bt':
            case 'b':
            case 'backtrace':
                if (!isset($args[1])) {
                    self::output($fd, 'Missing coroutine id.');
                    break;
                }
                $_cid = intval($args[1]);
                self::exec($fd, 'self::getBackTrace', [$_cid]);
                break;
            /**
             * 返回定时器列表
             * @link https://wiki.swoole.com/#/timer?id=list
             */
            case 'tl':
            case 'timer_list':
                self::exec($fd, 'self::getTimerList', []);
                break;
            /**
             * 返回 timer 的信息
             * @link https://wiki.swoole.com/#/timer?id=info
             */
            case 'ti':
            case 'timer_info':
                $timer_id = 0;
                if (isset($args[1])) {
                    $timer_id = intval($args[1]);
                }
                self::exec($fd, 'self::getTimerInfo', [$timer_id]);
                break;
            /**
             * 查看定时器状态
             * @link https://wiki.swoole.com/#/timer?id=stats
             */
            case 'ts':
            case 'timer_stats':
                self::exec($fd, 'self::getTimerStats', []);
                break;
            case 'i':
            case 'info':
                if (!isset($args[1])) {
                    self::output($fd, "Missing fd.");
                    break;
                }
                $_fd = intval($args[1]);
                $info = $serv->getClientInfo($_fd);
                if (!$info) {
                    self::output($fd, "connection $_fd not found.");
                } else {
                    self::output($fd, var_export($info, true));
                }
                break;
            case 'l':
            case 'list':
                $tmp = array();
                foreach ($serv->connections as $fd) {
                    $tmp[] = $fd;
                    if (count($tmp) > self::PAGESIZE) {
                        self::output($fd, json_encode($tmp));
                        $tmp = array();
                    }
                }
                if (count($tmp) > 0) {
                    self::output($fd, json_encode($tmp));
                }
                break;
            case 'q':
            case 'quit':
                $serv->close($fd);
                break;
            default:
                self::output($fd, "unknown command [$cmd]");
                break;
        }
    }
}
