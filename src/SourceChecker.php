<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/VTelegram/src/autoload.php';
require_once __DIR__ . '/SourceBot.php';
require_once __DIR__ . '/VKAPI.php';

class SourceChecker
{
    public static $cachedMaterials = [];
    public static $materials = [];
    public static $newFiles = [];
    public static $newLinks = [];

    public static $cachedWebinars = [];
    public static $webinars = [];
    public static $newWebinars = [];

    public static function printDebug(): void
    {
        print_r(self::$webinars);
        print_r(self::$materials);
        print_r(self::$newWebinars);
        print_r(self::$newFiles);
        print_r(self::$newLinks);
    }

    public static function loadData(string $login, string $password): void
    {
        self::$cachedMaterials = SourceBot::getCachedRawMaterials();
        self::$cachedWebinars = SourceBot::getCachedRawWebinars();
        self::$materials = SourceBot::getRawMaterials();
        if (!self::$materials) {
            // Unable to login. Relogin...
            SourceBot::reLogin($login, $password);
            self::$materials = SourceBot::getRawMaterials();
        }
        if (!self::$materials) {
            // Unable to login
            return;
        }
        self::$webinars = SourceBot::getRawWebinars();
    }

    public static function cacheData(): void
    {
        file_put_contents(SourceBot::CACHED_MATERIALS_PATH, json_encode(self::$materials));
        file_put_contents(SourceBot::CACHED_WEBINARS_PATH, json_encode(self::$webinars));
    }

    public static function checkNewWebinars(): void
    {
        self::$newWebinars = array_udiff(self::$webinars, self::$cachedWebinars, function ($entry1, $entry2) {
            return $entry1['id'] - $entry2['id'];
        });
    }

    public static function checkNewMaterials(): void
    {
        self::$newFiles = [];
        self::$newLinks = [];
        $newMaterials = array_diff_key(self::$materials, self::$cachedMaterials);
        foreach ($newMaterials as $sMaterials) {
            if (isset($sMaterials['files']))
                foreach ($sMaterials['files'] as $file)
                    self::$newFiles[] = $file;
            if (isset($sMaterials['links']))
                foreach ($sMaterials['links'] as $link)
                    self::$newLinks[] = $link;
        }
        foreach (self::$cachedMaterials as $subject => $sMaterials) {
            if (!isset($sMaterials['files']) and isset(self::$materials[$subject]['files']))
                foreach (self::$materials[$subject]['files'] as $file)
                    self::$newFiles[] = $file;
            if (!isset($sMaterials['links']) and isset(self::$materials[$subject]['links']))
                foreach (self::$materials[$subject]['links'] as $link)
                    self::$newLinks[] = $link;
            $diffFiles = array_udiff(self::$materials[$subject]['files'], $sMaterials['files'], function ($entry1, $entry2) {
                return $entry1['id'] - $entry2['id'];
            });
            foreach ($diffFiles as $file)
                self::$newFiles[] = $file;
            $diffLinks = array_udiff(self::$materials[$subject]['links'], $sMaterials['links'], function ($entry1, $entry2) {
                return $entry1['id'] - $entry2['id'];
            });
            foreach ($diffLinks as $link)
                self::$newLinks[] = $link;
        }
    }

    public static function broadcastUpdates(VTgRequestor $tg, array $userIds, VKAPI $vk, int $peerId): void
    {
        if (!empty(self::$newWebinars)) {
            $messageWebinars = self::composeWebinarsMessage();
            self::sendTelegramMessages($messageWebinars, $tg, $userIds);
            self::sendVKMessages($messageWebinars, $vk, $peerId);
        }
        if (!empty(self::$newFiles)) {
            $messageFiles = self::composeFilesMessage();
            self::sendTelegramMessages($messageFiles, $tg, $userIds);
            self::sendVKMessages($messageFiles, $vk, $peerId);
        }
        if (!empty(self::$newLinks)) {
            $messageLinks = self::composeLinksMessage();
            self::sendTelegramMessages($messageLinks, $tg, $userIds);
            self::sendVKMessages($messageLinks, $vk, $peerId);
        }
    }

    protected static function composeWebinarsMessage(): string
    {
        $message = "🔔 Новые онлайн-занятия:\n\n";
        foreach (self::$newWebinars as $entry) {
            $webinar = SourceBot::extractWebinarInfo($entry);
            $message .= SourceBot::composeWebinarSnippet($webinar);
        }
        return $message;
    }

    protected static function composeFilesMessage(): string
    {
        $message = "🔔 Новые файлы:\n\n";
        foreach (self::$newFiles as $file) {
            $file = SourceBot::extractFileInfo($file, true);
            $message .= SourceBot::composeFileSnippet($file, true);
        }
        return $message;
    }

    protected static function composeLinksMessage(): string
    {
        $message = "🔔 Новые ссылки:\n\n";
        foreach (self::$newLinks as $link) {
            $link = SourceBot::extractLinkInfo($link, true);
            $message .= SourceBot::composeLinkSnippet($link, true);
        }
        return $message;
    }

    protected static function sendTelegramMessages(string $text, VTgRequestor $tg, array $userIds): void
    {
        foreach ($userIds as $id) {
            $tg->sendMessage($id, $text, ['parse_mode' => null]);
        }
    }

    protected static function sendVKMessages(string $text, VKAPI $vk, int $peerId): void
    {
        $vk->sendMessage($peerId, $text);
    }
}
