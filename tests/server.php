<?php
require_once dirname(__DIR__) . '/src/RemoteShell.php';
require_once dirname(__DIR__) . '/src/functions.php';

use Swoole\Server;
use Swoole\Debugger\RemoteShell;

$serv = new Server("127.0.0.1", 9501);
$serv->set(array(
    'worker_num' => 2,   //工作进程数量
));
$serv->on('connect', function ($serv, $fd)
{
    echo "Client#$fd: Connect.\n";
});
$serv->on('receive', function ($serv, $fd, $from_id, $data)
{
    $serv->send($fd, 'Swoole: ' . $data);
});
$serv->on('close', function ($serv, $fd)
{
    echo "Client#$fd: Close.\n";
});

$serv->on('pipeMessage', function ($serv, $fd, $msg)
{
    var_dump($fd, $msg);
});

$serv->on("workerStart", function ($server, $workerId) {
    if ($workerId == 1) {
        return;
    }
    go(function() {
        Test::test1();
    });
});

function test3()
{
    global $serv;
    $serv->sendMessage(["hello", "world"], 1 - $serv->worker_id);
}

RemoteShell::listen($serv);

class Test
{
    static $a = 133;

    static function test1()
    {
        self::timerTest();
        self::test2();
    }

    static function test2()
    {
        while(true) {
            co::sleep(2.0);
        }
    }

    static function timerTest()
    {
        Swoole\Timer::tick(3000, function (int $timer_id, $param1, $param2) {
            echo "timer_id #$timer_id, after 3000ms.\n";
            echo "param1 is $param1, param2 is $param2.\n";

            Swoole\Timer::tick(14000, function ($timer_id) {
                echo "timer_id #$timer_id, after 14000ms.\n";
            });
        }, "A", "B");
    }
}

$serv->start();