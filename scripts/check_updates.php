<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/SafeMySQL/SafeMySQL.php';
require_once __DIR__ . '/../src/SourceChecker.php';

SourceChecker::loadData(SOURCE_LOGIN, SOURCE_PASSWORD);
SourceChecker::cacheData();
SourceChecker::checkNewWebinars();
SourceChecker::checkNewMaterials();

$tg = new VTgRequestor(TELEGRAM_TOKEN);
$vk = new VKAPI(VK_TOKEN);
$db = new SafeMySQL(DATABASE_OPTIONS);
$userIds = $db->getCol("SELECT `id` FROM ?n WHERE `subscription` = 1", SourceBot::DB_USERS_TABLE_NAME);

SourceChecker::broadcastUpdates($tg, $userIds, $vk, VK_PEER_ID);
