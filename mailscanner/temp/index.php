<?php

header('HTTP/1.1 403 Forbidden');
header('Content-Type: text/plain; charset=UTF-8');
echo "Forbidden: Direct access to temporary directory is disallowed.\n";
exit;
