<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Europe/Moscow');

session_start();

header('Content-Type: text/html; charset=UTF-8');

require_once('redis.class.php');

$db = new rediso();

$connect = $db->connect(['127.0.0.1', 6379, 'password' => '', 'database' => 0]);

echo ($connect) ? '<p>Connection <b>SUCCESS</b></p>' : '<p>Connection <b>FAILED</b></p>';

// Insert new field
$insert = $db->hAdd([
	'path' => 'mytable',
	'data' => ['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3'],
	'primary' => ['key2', 'key3'],
]);

echo '<h3>Inserd field</h3><pre>'.var_export($insert, true).'</pre>';

// Update field
$update = $db->hUpdate([
	'path' => 'mytable',
	'id' => 1,
	'data' => ['key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3']
]);

echo '<h3>Update field</h3><pre>'.var_export($update, true).'</pre>';

// Delete field
$delete = $db->hDelete([
	'path' => 'mytable',
	'id' => 1
]);

echo '<h3>Delete field</h3><pre>'.var_export($delete, true).'</pre>';

// Search
$opt = [
	'path' => 'mytable',
	'where' => [
		['id', '<', 4],
		['id', '=', 9],
	],
	'sort' => 'desc',
	'orderby' => 'id',
	'limit' => 5, // [0, 10]
	'search' => ['key2' => 'val'],
	// 'search' => '', // global search
];

$search = $db->hSearchAll($opt);

echo '<pre>'.var_export($search, true).'</pre>';

echo '<p>Last increment: '.$db->hLastIncrement('mytable').'</p>';

echo '<p>Query num: '.$db->query_num.'</p>';
?>