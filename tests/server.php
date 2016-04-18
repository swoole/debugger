<?php
require_once dirname(__DIR__) . '/src/RemoteShell.php';
$serv = new swoole_server("127.0.0.1", 9501);
$serv->set(array(
    'worker_num' => 8,   //工作进程数量
));
$serv->on('connect', function ($serv, $fd)
{
    echo "Client:Connect.\n";
});
$serv->on('receive', function ($serv, $fd, $from_id, $data)
{
    $serv->send($fd, 'Swoole: ' . $data);
    $serv->close($fd);
});
$serv->on('close', function ($serv, $fd)
{
    echo "Client: Close.\n";
});

RemoteShell::listen($serv);

$serv->start();