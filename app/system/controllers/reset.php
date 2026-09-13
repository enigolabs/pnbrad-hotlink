<?php

_auth();

$user = User::_info();
$ui->assign('_user', $user);

unset($_SESSION['nux-ip']);
unset($_SESSION['nux-mac']);
 r2(U . 'home', 's', 'Success...!!!');