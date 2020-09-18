<?php

if (!isset($_GET["token"]))
    return;

require_once __DIR__ . '/../config/config.php';

if ($_GET["token"] != TELEGRAM_TOKEN)
    return;

require_once __DIR__ . '/../vendor/VTelegram/src/autoload.php';
require_once __DIR__ . '/../vendor/SafeMySQL/SafeMySQL.php';
require_once __DIR__ . '/../src/SourceBot.php';

function isUserAllowed(int $id)
{
    return in_array($id, TELEGRAM_ALLOWED_USERS);
}

function processUser(SafeMySQL $db, int $id)
{
    if (!isUserAllowed($id))
        return false;
    $user = SourceBot::authorizeUser($db, $id);
    SourceBot::updateLastActivity($db, $id);
    return $user;
}

$db = new SafeMySQL(DATABASE_OPTIONS);

SourceBot::setToken(TELEGRAM_TOKEN);
SourceBot::enableDynamicCQHandlers();

SourceBot::registerStandardMessageHandler(function (VTgBotController $bot, VTgMessage $message) use (&$db) {
    if (!processUser($db, $message->from->id)) {
        $bot->execute($message->answer('Я вас не знаю.'));
        return;
    }
    $bot->execute($message->answer('Сообщение не может быть обработано. Вызовите /help для получения информации о возможностях бота.'));
});

SourceBot::registerCommandHandler('start', function (VTgBotController $bot, VTgMessage $message, string $data) use (&$db) {
    if (!processUser($db, $message->from->id)) {
        $bot->execute($message->answer('Я вас не знаю.'));
        return;
    }
    $answer = 'Я умею показывать данные с сайта source.unn.ru.' . "\n" .
        "Материалы: /materials\nОнлайн-занятия: /webinars\n" .
        //"В случае если список дисциплин не отображается, необходимо выполнить повторную авторизацию с помощью команды /relogin\n" .
        "Включить получение уведомлений при добавлении материалов: /subscribe";
    $bot->execute($message->answer($answer));
});

SourceBot::registerCommandHandler('help', function (VTgBotController $bot, VTgMessage $message, string $data) use (&$db) {
    if (!processUser($db, $message->from->id)) {
        $bot->execute($message->answer('Я вас не знаю.'));
        return;
    }
    $answer = 'Я умею показывать данные с сайта source.unn.ru.' . "\n" .
        "Материалы: /materials\nОнлайн-занятия: /webinars\n" .
        //"В случае если список дисциплин не отображается, необходимо выполнить повторную авторизацию с помощью команды /relogin\n" .
        "Включить получение уведомлений при добавлении материалов: /subscribe";
    $bot->execute($message->answer($answer));
});

SourceBot::registerCommandHandler('relogin', function (VTgBotController $bot, VTgMessage $message, string $data) use (&$db) {
    if (!processUser($db, $message->from->id)) {
        $bot->execute($message->answer('Я вас не знаю.'));
        return;
    }
    SourceBot::reLogin(SOURCE_LOGIN, SOURCE_PASSWORD);
    $bot->execute($message->answer('Повторная авторизация выполнена.'));
});

SourceBot::registerCommandHandler('materials', function (VTgBotController $bot, VTgMessage $message, string $data) use (&$db) {
    if (!processUser($db, $message->from->id)) {
        $bot->execute($message->answer('Я вас не знаю.'));
        return;
    }
    $subjects = SourceBot::getSubjects();
    $buttons = [];
    foreach ($subjects['full'] as $subject)
        $buttons[] = [$subject, 'S_' . md5($subject)];
    $extraParameters = [
        'reply_markup' => VTgInlineKeyboard::grid(2, $buttons)->json()
    ];
    $answer = "Дисциплины:\n";
    foreach ($subjects['full'] as $key => $subject)
        $answer .= ($key + 1) . '. ' . $subject . "\n";
    $bot->execute($message->answer($answer, $extraParameters));
});

SourceBot::registerCommandHandler('webinars', function (VTgBotController $bot, VTgMessage $message, string $data) use (&$db) {
    if (!processUser($db, $message->from->id)) {
        $bot->execute($message->answer('Я вас не знаю.'));
        return;
    }
    $answer = "Предстоящие онлайн-занятия: \n\n";
    $webinars = SourceBot::getCachedWebinars();
    if ($webinars['upcoming_count'] > 0) {
        foreach ($webinars['webinars'] as $entry)
            if ($entry['is_upcoming'])
                $answer .= '🖥 ' . $entry['date'] . ' ' . $entry['login'] . "\n" . $entry['title'] . "\nURL трансляции: " . $entry['stream_link'] . "\n" . $entry['comment'] . "\n";
    } else {
        $answer .= 'Нет предстоящих занятий.';
    }
    $extraParameters = [
        'reply_markup' => VTgInlineKeyboard::singleP('📚 Прошедшие', 'W_old')->json()
    ];
    $bot->execute($message->answer($answer, $extraParameters));
});

SourceBot::registerCommandHandler('subscribe', function (VTgBotController $bot, VTgMessage $message, string $data) use (&$db) {
    if (!processUser($db, $message->from->id)) {
        $bot->execute($message->answer('Я вас не знаю.'));
        return;
    }
    SourceBot::subscribeUser($db, $message->from->id);
    $bot->execute($message->answer("🔔 Теперь вы будете получать уведомления о новых материалах и онлайн-занятиях.\nОтключить уведомления: /unsubscribe"));
});

SourceBot::registerCommandHandler('unsubscribe', function (VTgBotController $bot, VTgMessage $message, string $data) use (&$db) {
    if (!processUser($db, $message->from->id)) {
        $bot->execute($message->answer('Я вас не знаю.'));
        return;
    }
    SourceBot::unsubscribeUser($db, $message->from->id);
    $bot->execute($message->answer("🔕 Вы больше не будете получать уведомления о новых материалах и онлайн-занятиях.\nОтключить уведомления: /unsubscribe"));
});

SourceBot::registerDynamicCQHandler('S_%a', function (VTgBotController $bot, VTgCallbackQuery $query, array $params) use (&$db) {
    if (!processUser($db, $query->from->id))
        return;
    $param = $params[1];
    if ($param == 'all') {
        $subjects = SourceBot::getSubjects();
        $buttons = [];
        foreach ($subjects['full'] as $subject)
            $buttons[] = [$subject,  'S_' . md5($subject)];
        $message = "Дисциплины:\n";
        foreach ($subjects['full'] as $key => $subject)
            $message .= ($key + 1) . '. ' . $subject . "\n";
        $bot->execute($query->editMessageText($message, ['reply_markup' => VTgInlineKeyboard::grid(2, $buttons)->json()]));
    } else {
        $subjectInfo = SourceBot::getSubjectInfo($param);
        if (isset($subjectInfo['error'])) {
            $bot->execute($query->editMessageText($subjectInfo['error']));
            return;
        }
        $message = $subjectInfo['subject'] . "\n\n" .
            '📄 Файлов: ' . $subjectInfo['files_count'] . "\n";
        $buttons = [];
        if ($subjectInfo['files_count'] > 0) {
            $message .= 'Последний: ' . $subjectInfo['files_last']['name'] . ' (загружен ' . $subjectInfo['files_last']['date'] . ' пользователем ' . $subjectInfo['files_last']['login'] . ")\nСкачать: " . $subjectInfo['files_last']['link'] . "\nКомментарий: " . $subjectInfo['files_last']['comment'] . "\n\n";
            $buttons[] = ['📄 Файлы', 'F_' . $param];
        }
        $message .= '🔗 Ссылок: ' . $subjectInfo['links_count'] . "\n";
        if ($subjectInfo['links_count'] > 0) {
            $message .= 'Последняя: ' . $subjectInfo['links_last']['link'] . ' (добавлена ' . $subjectInfo['links_last']['date'] . ' пользователем ' . $subjectInfo['links_last']['login'] . ")\nКомментарий: " . $subjectInfo['links_last']['comment'] . "\n";
            $buttons[] = ['🔗 Ссылки', 'L_' . $param];
        }
        $buttons[] = ['⬆️ Дисциплины', 'S_all'];
        $bot->execute($query->editMessageText($message, ['reply_markup' => VTgInlineKeyboard::row($buttons)->json()]));
    }
});

SourceBot::registerDynamicCQHandler('L_%a', function (VTgBotController $bot, VTgCallbackQuery $query, array $params) use (&$db) {
    if (!processUser($db, $query->from->id))
        return;
    $param = $params[1];
    define('LINKS_PER_PAGE', 7);
    $pagingData = explode('_', $param);
    $subjectHash = $pagingData[0];
    $pageCurrent = isset($pagingData[1]) ? (int) $pagingData[1] : 0;
    $hPageCurrent = $pageCurrent + 1;
    $buttons = [];
    $callbackDataPrefix = 'L_' . $subjectHash . '_';
    $links = SourceBot::getSubjectLinks($subjectHash);
    if (isset($links['error'])) {
        $bot->execute($query->editMessageText($links['error']));
        return;
    }
    $message = $links['subject'] . " (ссылки):\n\n";
    $linksCount = count($links['links']);
    $pagesCount = ceil($linksCount / LINKS_PER_PAGE);
    $pageLinks = array_slice(array_reverse($links['links']), $pageCurrent * LINKS_PER_PAGE, LINKS_PER_PAGE);
    foreach ($pageLinks as $link)
        $message .= SourceBot::composeLinkSnippet($link);
    if ($linksCount > LINKS_PER_PAGE) {
        if ($pageCurrent == 0)
            $buttons[0] = [
                ['⏹', $callbackDataPrefix . '0'],
                ['1', $callbackDataPrefix . '0'],
                ['▶️', $callbackDataPrefix . '1']
            ];
        elseif ($pageCurrent == $pagesCount - 1)
            $buttons[0] = [
                ['◀️', $callbackDataPrefix . ($pageCurrent - 1)],
                [$hPageCurrent, $callbackDataPrefix . $pageCurrent],
                ['⏹', $callbackDataPrefix . $pageCurrent]
            ];
        else
            $buttons[0] = [
                ['◀️', $callbackDataPrefix . ($pageCurrent - 1)],
                [$hPageCurrent, $callbackDataPrefix . $pageCurrent],
                ['▶️', $callbackDataPrefix . ($pageCurrent + 1)]
            ];
    }
    $buttons[] = [['📄 Файлы', 'F_' . $subjectHash], ['⬅️ Назад', 'S_' . $subjectHash], ['⬆️ Дисциплины', 'S_all']];
    $bot->execute($query->editMessageText($message, ['reply_markup' => VTgInlineKeyboard::table($buttons)->json()]));
    $bot->execute($query->answerWithText('Показана страница ' . $hPageCurrent . ' из ' . $pagesCount));
});

SourceBot::registerDynamicCQHandler('F_%a', function (VTgBotController $bot, VTgCallbackQuery $query, array $params) use (&$db) {
    if (!processUser($db, $query->from->id))
        return;
    $param = $params[1];
    define('FILES_PER_PAGE', 7);
    $pagingData = explode('_', $param);
    $subjectHash = $pagingData[0];
    $pageCurrent = isset($pagingData[1]) ? (int) $pagingData[1] : 0;
    $hPageCurrent = $pageCurrent + 1;
    $buttons = [];
    $callbackDataPrefix = 'F_' . $subjectHash . '_';
    $files = SourceBot::getSubjectFiles($subjectHash);
    if (isset($files['error'])) {
        $bot->execute($query->editMessageText($files['error']));
        return;
    }
    $message = $files['subject'] . " (файлы):\n\n";
    $filesCount = count($files['files']);
    $pagesCount = ceil($filesCount / FILES_PER_PAGE);
    $pageFiles = array_slice(array_reverse($files['files']), $pageCurrent * FILES_PER_PAGE, FILES_PER_PAGE);
    foreach ($pageFiles as $file)
        $message .= SourceBot::composeFileSnippet($file);
    if ($filesCount > FILES_PER_PAGE) {
        if ($pageCurrent == 0)
            $buttons[0] = [
                ['⏹', $callbackDataPrefix . '0'],
                ['1', $callbackDataPrefix . '0'],
                ['▶️', $callbackDataPrefix . '1']
            ];
        elseif ($pageCurrent == $pagesCount - 1)
            $buttons[0] = [
                ['◀️', $callbackDataPrefix . ($pageCurrent - 1)],
                [$hPageCurrent, $callbackDataPrefix . $pageCurrent],
                ['⏹', $callbackDataPrefix . $pageCurrent]
            ];
        else
            $buttons[0] = [
                ['◀️', $callbackDataPrefix . ($pageCurrent - 1)],
                [$hPageCurrent, $callbackDataPrefix . $pageCurrent],
                ['▶️', $callbackDataPrefix . ($pageCurrent + 1)]
            ];
    }
    $buttons[] = [['🔗 Ссылки', 'L_' . $subjectHash], ['⬅️ Назад', 'S_' . $subjectHash], ['⬆️ Дисциплины', 'S_all']];
    $bot->execute($query->editMessageText($message, ['reply_markup' => VTgInlineKeyboard::table($buttons)->json()]));
    $bot->execute($query->answerWithText('Показана страница ' . $hPageCurrent . ' из ' . $pagesCount));
});

SourceBot::registerDynamicCQHandler('W_%a', function (VTgBotController $bot, VTgCallbackQuery $query, array $params) use (&$db) {
    if (!processUser($db, $query->from->id))
        return;
    $param = $params[1];
    define('WEBINARS_PER_PAGE', 5);
    $pagingData = explode('_', $param);
    $pageCurrent = isset($pagingData[1]) ? (int) $pagingData[1] : 0;
    $hPageCurrent = $pageCurrent + 1;
    $buttons = [];
    if ($pagingData[0] == 'old') {
        $message = "Прошедшие онлайн-занятия: \n\n";
        $webinars = SourceBot::getCachedWebinars();
        $pagesCount = ceil($webinars['old_count'] / WEBINARS_PER_PAGE);
        if ($webinars['old_count'] > 0) {
            $pageWebinars = array_slice(array_reverse($webinars['webinars']), $pageCurrent * WEBINARS_PER_PAGE, WEBINARS_PER_PAGE);
            foreach ($pageWebinars as $entry)
                if (!$entry['is_upcoming'])
                    $message .= SourceBot::composeWebinarSnippet($entry);
            if ($webinars['old_count'] > WEBINARS_PER_PAGE) {
                if ($pageCurrent == 0)
                    $buttons[0] = [
                        ['⏹', 'W_old_0'],
                        ['1', 'W_old_0'],
                        ['▶️', 'W_old_1']
                    ];
                elseif ($pageCurrent == $pagesCount - 1)
                    $buttons[0] = [
                        ['◀️', 'W_old_' . ($pageCurrent - 1)],
                        [$hPageCurrent, 'W_old_' . $pageCurrent],
                        ['⏹', 'W_old_' . $pageCurrent]
                    ];
                else
                    $buttons[0] = [
                        ['◀️', 'W_old_' . ($pageCurrent - 1)],
                        [$hPageCurrent, 'W_old_' . $pageCurrent],
                        ['▶️', 'W_old_' . ($pageCurrent + 1)]
                    ];
            }
        } else {
            $message .= 'Нет прошедших занятий.';
        }
        $buttons[] = [['🗓 Предстоящие', 'W_upcoming']];
        $bot->execute($query->editMessageText($message, ['reply_markup' => VTgInlineKeyboard::table($buttons)->json()]));
        $bot->execute($query->answerWithText('Показана страница ' . $hPageCurrent . ' из ' . $pagesCount));
    } else {
        $message = "Предстоящие онлайн-занятия: \n\n";
        $webinars = SourceBot::getCachedWebinars();
        if ($webinars['upcoming_count'] > 0) {
            foreach ($webinars['webinars'] as $entry)
                if ($entry['is_upcoming'])
                    $message .= SourceBot::composeWebinarSnippet($entry);
        } else {
            $message .= 'Нет предстоящих занятий.';
        }
        $bot->execute($query->editMessageText($message, ['reply_markup' => VTgInlineKeyboard::singleP('📚 Прошедшие', 'W_old')->json()]));
    }
});

SourceBot::processUpdatePost();
