<?php

class RemoteShell
{
    static $contexts = array();

    /**
     * @var \swoole\server
     */
    static $serv;

    static $menu = array(
        "p|print [variant]\t打印某PHP变量的值",
        "e|exec [code]\t执行PHP代码",
        "w|worker [id]\t切换Worker进程",
        "l|list\t打印服务器所有连接的fd",
        "s|stats\t打印服务器状态",
        "c|coros\t打印协程列表",
        "b|bt\t打印协程调用栈",
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
        $port->set(array(
            "open_eof_split" => true,
            'package_eof' => "\r\n",
        ));
        $port->on("Connect", array(__CLASS__, 'onConnect'));
        $port->on("Close", array(__CLASS__, 'onClose'));
        $port->on("Receive", array(__CLASS__, 'onReceive'));
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

    static function onPipeMessage($serv, $from_worker_id, $message)
    {
        $arr = explode("\r\n", $message, 3);
        ob_start();
        eval($arr[2] . ";");
        self::output($arr[1], ob_get_clean());
    }

    static protected function execCode($fd, $code)
    {
        //不在当前Worker进程
        if (self::$contexts[$fd]['worker_id'] != self::$serv->worker_id) {
            self::$serv->sendMessage(__CLASS__ . "\r\n$fd\r\n" . $code, self::$contexts[$fd]['worker_id']);
        } else {
            ob_start();
            eval($code . ";");
            self::output($fd, ob_get_clean());
        }
    }

    static function getCoros()
    {
        var_export(iterator_to_array(Swoole\Coroutine::listCoroutines()));
    }

    static function getBackTrace($_cid)
    {
        $info = Co::getBackTrace($_cid);
        if (!$info) {
            echo "coroutine $_cid not found.";
        } else {
            echo get_debug_print_backtrace($info);
        }
    }

    /**
     * @param $serv \swoole\server
     * @param $fd
     * @param $reactor_id
     * @param $data
     */
    static function onReceive($serv, $fd, $reactor_id, $data)
    {
        $args = explode(" ", $data, 2);
        $cmd = trim($args[0]);
        unset($args[0]);
        switch ($cmd) {
            case 'w':
            case 'worker':
                if (!isset($args[1])) {
                    self::output($fd, "invalid command.");
                    break;
                }
                $dstWorkerId = intval($args[1]);
                self::$contexts[$fd]['worker_id'] = $dstWorkerId;
                self::output($fd, "[switching to worker " . self::$contexts[$fd]['worker_id'] . "]");
                break;
            case 'e':
            case 'exec':
                self::execCode($fd, $args[1]);
                break;
            case 'p':
            case 'print':
                $var = trim($args[1]);
                self::execCode($fd, 'var_dump(' . $var . ')');
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
                self::execCode($fd, 'RemoteShell::getCoros()');
                break;
            /**
             * 查看协程堆栈
             */
            case 'bt':
            case 'b':
            case 'backtrace':
                if (empty($args[1])) {
                    self::output($fd, "invalid command [" . trim($args[1]) . "].");
                    break;
                }
                $_cid = intval($args[1]);
                self::execCode($fd, "RemoteShell::getBackTrace($_cid)");
                break;
            case 'i':
            case 'info':
                if (empty($args[1])) {
                    self::output($fd, "invalid command [" . trim($args[1]) . "].");
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
                self::output($fd, "unknow command[$cmd]");
                break;
        }
    }
}


function get_debug_print_backtrace($traces)
{
    $ret = array();
    foreach ($traces as $i => $call) {
        $object = '';
        if (isset($call['class'])) {
            $object = $call['class'] . $call['type'];
            if (is_array($call['args'])) {
                foreach ($call['args'] as &$arg) {
                    get_arg($arg);
                }
            }
        }

        $ret[] = '#' . str_pad($i, 3, ' ')
            . $object . $call['function'] . '(' . implode(', ', $call['args'])
            . ') called at [' . $call['file'] . ':' . $call['line'] . ']';
    }

    return implode("\n", $ret);
}

function get_arg(&$arg)
{
    if (is_object($arg)) {
        $arr = (array)$arg;
        $args = array();
        foreach ($arr as $key => $value) {
            if (strpos($key, chr(0)) !== false) {
                $key = '';    // Private variable found
            }
            $args[] = '[' . $key . '] => ' . get_arg($value);
        }

        $arg = get_class($arg) . ' Object (' . implode(',', $args) . ')';
    }
}
