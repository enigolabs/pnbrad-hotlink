<?php
register_menu("System Tools", true, "systool", 'AFTER_SETTINGS', 'ion ion-hammer');

function systool()
{
    global $ui;
    _admin();
    $ui->assign('_title', 'System Tools');
    $ui->assign('_system_menu', 'systool');
    $admin = Admin::_info();
    $ui->assign('_admin', $admin);


    $rootpass = getenv('ROOT_PASSWORD', true) ?: getenv('ROOT_PASSWORD');
    $server   = "localhost"; 
    $username = "root";
    $password = $rootpass; 

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restart']) && $_POST['restart'] === 'true')
    {
        $command  = 'reboot';
        $connection = ssh2_connect($server, 22);
        ssh2_auth_password($connection, $username, $password);
        $stream = ssh2_exec($connection, $command);
        if (is_resource($stream)) {
       stream_set_blocking($stream, true);
        r2(U . 'plugin/systool', 's', 'Restart Success!');
       } else {
        r2(U . 'plugin/systool', 'e', 'Restart Failed!');
            }
    }		
			
			
			$filecron = 'crontab';
			if (!file_exists($filecron)) {  
			$filecron = 'crontab-last';
			}
	
			$cront = file_get_contents($filecron);
			$ui->assign('cront', $cront);
			
			if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cron'])) {
				if (file_exists($filecron)) {  
				unlink($filecron); 
				}
			$data = $_POST['cron'];
			$ret = file_put_contents('crontab', $data, FILE_APPEND | LOCK_EX);
			if($ret === false) {
				r2(U . 'plugin/systool', 's', 'Error!');
				}
				else {
						$command  = 'reboot';
						$connection = ssh2_connect($server, 22);
						ssh2_auth_password($connection, $username, $password);
						$stream = ssh2_exec($connection, $command);
						if (is_resource($stream)) {
                           stream_set_blocking($stream, true);
                            r2(U . 'plugin/systool', 's', 'Cron Update Success!');
                           } else {
                            r2(U . 'plugin/systool', 'e', 'Cron Update Failed!');
                                }
				}
			}
    $ui->display('systool.tpl');
}