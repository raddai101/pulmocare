<?php
require 'backend/functions/functions_tmp_debug.php';
$files = get_included_files();
foreach ($files as $f) { echo $f . '\n'; }
