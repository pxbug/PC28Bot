<?php
require __DIR__ . '/../src/bootstrap.php';
use App\Auth;
use App\Helper;

Auth::logout();
Helper::flash('toast', '已退出登录');
header('Location: /login.php');
exit;