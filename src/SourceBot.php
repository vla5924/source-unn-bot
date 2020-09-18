<?php

require_once __DIR__ . '/../vendor/VTelegram/src/autoload.php';
require_once __DIR__ . '/../vendor/SafeMySQL/SafeMySQL.php';

class SourceBot extends VTgBot
{
    use VTgCQHandlers;

    const SERVER_TIME_SHIFT = -7200;
    const DB_USERS_TABLE_NAME = 'sourceunn_users';
    const CACHED_PATH = __DIR__ . '/../cached';
    const CACHED_PHPSESSID_PATH = self::CACHED_PATH . '/PHPSESSID.dat';
    const CACHED_MATERIALS_PATH = self::CACHED_PATH . '/materials.json';
    const CACHED_WEBINARS_PATH = self::CACHED_PATH . '/webinars.json';
    const FILE_LINK_PREFIX = 'https://source.unn.ru/files/file.php?hash=';
    const SOURCE_URL = 'https://source.unn.ru';
    const SOURCE_MATERIALS_URL = self::SOURCE_URL . '/ajax/get/materials.php';
    const SOURCE_WEBINARS_URL = self::SOURCE_URL . '/ajax/get/webinars.php';
    const ERROR_UNKNOWN_DISCIPLINE = 'Дисциплина не найдена.';

    static public function authorizeUser(SafeMySQL $db, int $fromId): array
    {
        $userData = [
            'is_new' => true,
            'id' => 0,
            'subscription' => 0
        ];
        $userRawData = $db->getRow("SELECT `id`, `subscription` FROM ?n WHERE `id` = ?i", self::DB_USERS_TABLE_NAME, $fromId);
        if ($userRawData) {
            $userData["is_new"] = false;
            $userData = array_merge($userData, $userRawData);
        } else {
            $db->query("INSERT INTO ?n SET `id` = ?i", self::DB_USERS_TABLE_NAME, $fromId);
            $userData["id"] = $fromId;
        }
        return $userData;
    }

    static public function updateLastActivity(SafeMySQL $db, int $userId): void
    {
        $db->query("UPDATE ?n SET `last_activity` = ?i WHERE `id` = ?i", self::DB_USERS_TABLE_NAME, time(), $userId);
    }

    static public function subscribeUser(SafeMySQL $db, int $userId): void
    {
        $db->query("UPDATE ?n SET `subscription` = 1 WHERE `id` = ?i", self::DB_USERS_TABLE_NAME, $userId);
    }

    static public function unsubscribeUser(SafeMySQL $db, int $userId): void
    {
        $db->query("UPDATE ?n SET `subscription` = 0 WHERE `id` = ?i", self::DB_USERS_TABLE_NAME, $userId);
    }

    static public function extractWebinarInfo(array &$rawInfo): array
    {
        $date = $rawInfo['date'] . ' ' . $rawInfo['time'];
        $isUpcoming = (strtotime($date) > time() + self::SERVER_TIME_SHIFT);
        $webinar = [
            'id' => $rawInfo['id'],
            'title' => $rawInfo['title'],
            'subject' => $rawInfo['discipline'],
            'login' => $rawInfo['login'],
            'stream_link' => $rawInfo['url_stream'],
            'record_link' => $rawInfo['url_record'],
            'comment' => $rawInfo['comment'],
            'date' => $date,
            'is_upcoming' => $isUpcoming
        ];
        return $webinar;
    }

    static public function extractFileInfo(array &$rawInfo, bool $includeSubject = false): array
    {
        $file = [
            'name' => $rawInfo['file_src_name'],
            'size' => $rawInfo['file_size'],
            'date' => $rawInfo['file_date'],
            'comment' => $rawInfo['comment'],
            'hash' => $rawInfo['file_hash'],
            'login' => $rawInfo['login'],
            'link' => self::FILE_LINK_PREFIX . $rawInfo['file_hash']
        ];
        if($includeSubject)
            $file['subject'] = $rawInfo['discipline'];
        return $file;
    }

    static public function extractLinkInfo(array &$rawInfo, bool $includeSubject = false): array
    {
        $link = [
            'date' => $rawInfo['datetime'],
            'comment' => $rawInfo['comment'],
            'login' => $rawInfo['login'],
            'link' => $rawInfo['link']
        ];
        if ($includeSubject)
            $link['subject'] = $rawInfo['discipline'];
        return $link;
    }

    static public function getRawMaterials()
    {
        $sessionId = self::getCachedSessionId();
        $ch = curl_init(self::SOURCE_MATERIALS_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID=" . $sessionId);
        $result = curl_exec($ch);
        curl_close($ch);
        $result = substr($result, strpos($result, PHP_EOL));
        $data = json_decode($result, true);
        return $data;
    }

    static public function getRawWebinars()
    {
        $sessionId = self::getCachedSessionId();
        $ch = curl_init(self::SOURCE_WEBINARS_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID=" . $sessionId);
        $result = curl_exec($ch);
        curl_close($ch);
        $result = substr($result, strpos($result, PHP_EOL));
        $data = json_decode($result, true);
        return $data;
    }

    static public function getWebinars()
    {
        $data = self::getRawWebinars();
        $webinars = [];
        $upcomingCount = 0;
        $oldCount = 0;
        foreach ($data as $entry) {
            $webinar = self::extractWebinarInfo($entry);
            if ($webinar['is_upcoming'])
                $upcomingCount++;
            else
                $oldCount++;
            $webinars[] = $webinar;
        }
        return ['upcoming_count' => $upcomingCount, 'old_count' => $oldCount, 'webinars' => $webinars];
    }

    static public function getCachedRawMaterials()
    {
        return json_decode(file_get_contents(self::CACHED_MATERIALS_PATH), true);
    }

    static public function getCachedRawWebinars()
    {
        return json_decode(file_get_contents(self::CACHED_WEBINARS_PATH), true);
    }

    static public function getCachedWebinars()
    {
        $data = self::getCachedRawWebinars();
        $webinars = [];
        $upcomingCount = 0;
        $oldCount = 0;
        foreach ($data as $entry) {
            $webinar = self::extractWebinarInfo($entry);
            if ($webinar['is_upcoming'])
                $upcomingCount++;
            else
                $oldCount++;
            $webinars[] = $webinar;
        }
        return ['upcoming_count' => $upcomingCount, 'old_count' => $oldCount, 'webinars' => $webinars];
    }

    static public function reLogin(string $login, string $password): void
    {
        $ch = curl_init(self::SOURCE_URL . '/');
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'login' => $login,
            'password' => $password,
            'action' => 'log-in',
            'submit' => 'Войти'
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        $sessionPos = mb_strpos($result, 'PHPSESSID');
        if ($sessionPos !== false) {
            $sessionPos += 10;
            $sessionLen = mb_strpos($result, ';', $sessionPos) - $sessionPos;
            $sessionId = mb_substr($result, $sessionPos, $sessionLen);
            file_put_contents(self::CACHED_PHPSESSID_PATH, $sessionId . PHP_EOL . time());
        }
    }

    static public function getSubjects(): array
    {
        $materials = self::getCachedRawMaterials();
        $fulls = array_keys($materials);
        $shorts = [];
        foreach ($fulls as $name)
            $shorts[] = mb_strlen($name) >= 35 ? mb_substr($name, 0, 33) . '...' : $name;
        return ['full' => $fulls, 'short' => $shorts];
    }

    static public function getSubjectInfo(string $subjectHash): array
    {
        $materials = self::getCachedRawMaterials();
        $subject = false;
        foreach ($materials as $key => $data)
            if (md5($key) == $subjectHash) {
                $subject = $key;
                break;
            }
        if (!$subject)
            return ['error' => self::ERROR_UNKNOWN_DISCIPLINE];
        $result = [
            'subject' => $subject,
            'files_count' => 0,
            'links_count' => 0,
            'files_last' => [],
            'links_last' => []
        ];
        if (isset($materials[$subject]['files'])) {
            $result['files_count'] = count($materials[$subject]['files']);
            $file = array_pop($materials[$subject]['files']);
            $result['files_last'] = self::extractFileInfo($file);
        }
        if (isset($materials[$subject]['links'])) {
            $result['links_count'] = count($materials[$subject]['links']);
            $link = array_pop($materials[$subject]['links']);
            $result['links_last'] = self::extractLinkInfo($link);
        }
        return $result;
    }

    static public function getSubjectFiles(string $subjectHash): array
    {
        $materials = self::getCachedRawMaterials();
        $subject = false;
        foreach ($materials as $key => $data)
            if (md5($key) == $subjectHash) {
                $subject = $key;
                break;
            }
        if (!$subject)
            return ['error' => self::ERROR_UNKNOWN_DISCIPLINE];
        if (!isset($materials[$subject]['files']))
            return ['subject' => $subject, 'files' => []];
        $result = [];
        foreach ($materials[$subject]['files'] as $file)
            $result[] = self::extractFileInfo($file);
        return ['subject' => $subject, 'files' => $result];
    }

    static public function getSubjectLinks(string $subjectHash): array
    {
        $materials = self::getCachedRawMaterials();
        $subject = false;
        foreach ($materials as $key => $data)
            if (md5($key) == $subjectHash) {
                $subject = $key;
                break;
            }
        if (!$subject)
            return ['error' => self::ERROR_UNKNOWN_DISCIPLINE];
        if (!isset($materials[$subject]['links']))
            return ['subject' => $subject, 'links' => []];
        $result = [];
        foreach ($materials[$subject]['links'] as $link)
            $result[] = self::extractLinkInfo($link);
        return ['subject' => $subject, 'links' => $result];
    }

    static public function getCachedSessionId(): string
    {
        $data = file_get_contents(self::CACHED_PHPSESSID_PATH);
        $sessionId = substr($data, 0, strpos($data, PHP_EOL));
        return $sessionId;
    }

    static public function composeWebinarSnippet(array &$webinar): string
    {
        $text = '';
        if ($webinar['is_upcoming']) {
            $text .= '🖥 ' . $webinar['date'] . ' ' . $webinar['login'] . "\n" . $webinar['title'] . "\nURL трансляции: " . $webinar['stream_link'] . "\n" . $webinar['comment'] . "\n";
        } else {
            $text .= '🖥 ' . $webinar['date'] . ' ' . $webinar['login'] . "\n" . $webinar['title'] . "\n";
            if ($webinar['record_link'])
                $text .= "URL записи: " . $webinar['record_link'] . "\n";
            else
                $text .= "URL трансляции: " . $webinar['stream_link'] . "\n";
            $text .= $webinar['comment'] . "\n";
        }
        return $text;
    }

    static public function composeFileSnippet(array &$file, bool $includeSubject = false): string
    {
        $text = '📄 ' . $file['date'] . ' ' . $file['login'];
        if($includeSubject)
            $text .= ' (' . $file['subject'] . ')';
        $text .= "\n" . $file['name'] . "\n" . $file['link'] . "\n" . $file['comment'] . "\n";
        return $text;
    }

    static public function composeLinkSnippet(array &$link, bool $includeSubject = false): string
    {
        $text = '🔗 ' . $link['date'] . ' ' . $link['login'];
        if ($includeSubject)
            $text .= ' (' . $link['subject'] . ')';
        $text .= "\n" . $link['link'] . "\n" . $link['comment'] . "\n";
        return $text;
    }
}
