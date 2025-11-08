<?php
echo "php_ini_loaded_file: ", php_ini_loaded_file(), "\n\n";
echo "php_ini_scanned_files:\n", php_ini_scanned_files(), "\n\n";
echo "session.save_path: ", ini_get('session.save_path'), "\n";
