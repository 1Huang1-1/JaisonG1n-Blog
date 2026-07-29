<?php

$root = isset($argv[1]) ? rtrim((string) $argv[1], '/\\') : dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$files = array();

foreach ($iterator as $file) {
	if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
		$files[] = $file->getPathname();
	}
}

sort($files, SORT_STRING);
foreach ($files as $file) {
	$source = file_get_contents($file);
	if (!is_string($source)) {
		throw new RuntimeException('Could not read ' . $file);
	}
	token_get_all($source, TOKEN_PARSE);
	echo 'No syntax errors detected in ' . str_replace($root . DIRECTORY_SEPARATOR, '', $file) . "\n";
}
