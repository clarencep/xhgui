<?php

// profile only when 'xhprof_enable' in Cookie and is the date of today.
if (@$_COOKIE['xhprof_enable'] === date('Y-m-d')){
    call_user_func(function(){
        // use annoymouse function to wrap header.php to avoid naming pollution.
        require(__DIR__ . '/../external/header.php');
    });
}
