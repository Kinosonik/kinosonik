<?php
@file_put_contents('/var/config/logs/riders/ping.log', date('c')." ping\n", FILE_APPEND);
echo "ok";