<?php

/**
 * -------------------------------------------------------------------------
 * Order plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Order.
 *
 * Order is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Order is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Order. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2009-2023 by Order plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsGLPI/order
 * -------------------------------------------------------------------------
 */

/**
 * Read-only diagnostic for order notifications.
 *
 * Answers one question: where does the chain break - configuration, recipients,
 * template, queueing, or sending? Nothing is written to the database.
 *
 * Run from the GLPI root:
 *   php plugins/order/tools/diagnose_notifications.php
 *   php plugins/order/tools/diagnose_notifications.php --order=123
 */

use Glpi\Kernel\Kernel;

/**
 * Fail with an explanation instead of a stack trace: this script is meant to be
 * run on a production box by someone chasing a different problem entirely.
 */
function bail(string $title, array $hints): never
{
    fwrite(STDERR, "\n" . $title . "\n");
    foreach ($hints as $hint) {
        fwrite(STDERR, '  - ' . $hint . "\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

// PHP extensions GLPI needs before its kernel can even reach the database.
// Without this guard a missing mysqli surfaces as a cryptic
// "Attempted to call function mysqli_report from the global namespace".
$missing = array_values(array_filter(
    ['mysqli', 'json', 'mbstring'],
    static fn(string $ext): bool => !extension_loaded($ext),
));
if ($missing !== []) {
    bail(
        'This PHP binary (' . PHP_BINARY . ', ' . PHP_VERSION . ') is missing: ' . implode(', ', $missing),
        [
            'Run the script with the same PHP that serves GLPI - the web server (php-fpm) and the CLI'
                . ' often load different extension sets.',
            'Find a usable binary: for p in php php8.2 php8.3 php8.4; do printf "%s: " "$p";'
                . ' "$p" -r \'echo extension_loaded("mysqli") ? "ok" : "no mysqli";\' 2>/dev/null; echo; done',
            'Debian/Ubuntu: apt install php8.3-mysql, then phpenmod -v 8.3 -s cli mysqli',
            'RHEL/Alma: dnf install php-mysqlnd',
        ],
    );
}

// The plugin lives under plugins/ or marketplace/, so walk up until the GLPI
// root shows itself; --glpi=/path overrides the search.
$root = null;
foreach ($argv as $arg) {
    if (preg_match('/^--glpi=(.+)$/', $arg, $m)) {
        $root = rtrim($m[1], '/');
    }
}
if ($root === null) {
    $candidate = __DIR__;
    for ($depth = 0; $depth < 6; $depth++) {
        $candidate = dirname($candidate);
        if (is_file($candidate . '/vendor/autoload.php') && is_dir($candidate . '/src')) {
            $root = $candidate;
            break;
        }
    }
}

if ($root === null || !is_file($root . '/vendor/autoload.php')) {
    bail('Could not locate the GLPI root above ' . __DIR__, [
        'Pass it explicitly: php ' . basename(__FILE__) . ' --glpi=/var/www/html/glpi',
    ]);
}

chdir($root);
require_once $root . '/vendor/autoload.php';

try {
    (new Kernel())->boot();
} catch (\Throwable $e) {
    bail('GLPI could not start: ' . $e->getMessage(), [
        'Root used: ' . $root,
        'Run the script as the web server user, e.g. sudo -u www-data ...',
        'Check that ' . $root . '/config/config_db.php is readable by that user.',
    ]);
}

$_SESSION['glpi_currenttime']          = date('Y-m-d H:i:s');
$_SESSION['glpiactive_entity']         = 0;
$_SESSION['glpiactiveentities']        = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiname']                  = 'cli_diagnostic';

/** @var DBmysql $DB */
/** @var array $CFG_GLPI */
global $DB, $CFG_GLPI;

$order_id = null;
foreach ($argv as $arg) {
    if (preg_match('/^--order=(\d+)$/', $arg, $m)) {
        $order_id = (int) $m[1];
    }
}

$problems = [];
$notes    = [];

function line(string $text = ''): void
{
    echo $text . "\n";
}

function check(string $label, bool $ok, string $detail = '', bool $fatal = true): bool
{
    global $problems;

    printf("  [%s] %-48s %s\n", $ok ? ' OK ' : ($fatal ? 'BLAD' : 'UWAGA'), $label, $detail);
    if (!$ok && $fatal) {
        $problems[] = $label . ($detail !== '' ? ' - ' . $detail : '');
    }

    return $ok;
}

line();
line('=== Diagnostyka powiadomien wtyczki Order ===');
line('GLPI ' . (defined('GLPI_VERSION') ? GLPI_VERSION : '?') . ', PHP ' . PHP_VERSION);
line();

// --- 1. Globalne ustawienia powiadomien -----------------------------------
line('1. Globalne ustawienia GLPI');
check('Powiadomienia wlaczone (use_notifications)', !empty($CFG_GLPI['use_notifications']));
check('Tryb e-mail wlaczony (notifications_mailing)', !empty($CFG_GLPI['notifications_mailing']));
check(
    'Jakikolwiek aktywny tryb wysylki',
    Notification_NotificationTemplate::hasActiveMode(),
    'Ustawienia > Powiadomienia',
);
check('Adres nadawcy (from_email) ustawiony', !empty($CFG_GLPI['from_email']), (string) ($CFG_GLPI['from_email'] ?? ''));
check(
    'url_base ustawiony',
    !empty($CFG_GLPI['url_base']),
    (string) ($CFG_GLPI['url_base'] ?? ''),
);
line();

// --- 2. Powiadomienia wtyczki i ich odbiorcy ------------------------------
line('2. Powiadomienia wtyczki (kluczowe: KAZDE musi miec odbiorce)');
$without_targets = [];
$inactive        = [];
$no_template     = [];

$iterator = $DB->request([
    'FROM'  => 'glpi_notifications',
    'WHERE' => ['itemtype' => 'PluginOrderOrder'],
    'ORDER' => 'event',
]);

if (count($iterator) === 0) {
    check('Powiadomienia wtyczki istnieja', false, 'brak wpisow - uruchom aktualizacje wtyczki');
}

foreach ($iterator as $notification) {
    $targets = countElementsInTable('glpi_notificationtargets', [
        'notifications_id' => $notification['id'],
    ]);
    $templates = countElementsInTable('glpi_notifications_notificationtemplates', [
        'notifications_id' => $notification['id'],
    ]);

    $flags = [];
    if (!$notification['is_active']) {
        $flags[]    = 'NIEAKTYWNE';
        $inactive[] = $notification['event'];
    }
    if ($targets === 0) {
        $flags[]           = 'BRAK ODBIORCOW';
        $without_targets[] = $notification['event'];
    }
    if ($templates === 0) {
        $flags[]       = 'BRAK SZABLONU';
        $no_template[] = $notification['event'];
    }

    printf(
        "  %-16s odbiorcow=%d szablonow=%d %s\n",
        $notification['event'],
        $targets,
        $templates,
        $flags === [] ? '' : '<<< ' . implode(', ', $flags),
    );
}

if ($without_targets !== []) {
    $problems[] = 'Powiadomienia bez odbiorcow (nie wysla sie NIGDY): '
        . implode(', ', $without_targets)
        . ' - dodaj odbiorce w Ustawienia > Powiadomienia > Powiadomienia > [nazwa] > Odbiorcy';
}
if ($inactive !== []) {
    $problems[] = 'Powiadomienia nieaktywne: ' . implode(', ', $inactive);
}
if ($no_template !== []) {
    $problems[] = 'Powiadomienia bez przypisanego szablonu: ' . implode(', ', $no_template);
}
line();

// --- 3. Szablony i ich tlumaczenia ----------------------------------------
line('3. Szablony powiadomien (brak tlumaczenia = cicha awaria renderowania)');
$templates = $DB->request([
    'FROM'  => 'glpi_notificationtemplates',
    'WHERE' => ['itemtype' => 'PluginOrderOrder'],
]);
foreach ($templates as $template) {
    $translations = countElementsInTable('glpi_notificationtemplatetranslations', [
        'notificationtemplates_id' => $template['id'],
    ]);
    printf(
        "  %-28s tlumaczen=%d %s\n",
        $template['name'],
        $translations,
        $translations === 0 ? '<<< BRAK TLUMACZENIA' : '',
    );
    if ($translations === 0) {
        $problems[] = 'Szablon bez tlumaczenia: ' . $template['name'];
    }
}
line();

// --- 4. Kolejka powiadomien ------------------------------------------------
line('4. Kolejka powiadomien (glpi_queuednotifications)');
$pending = countElementsInTable('glpi_queuednotifications', ['is_deleted' => 0]);
$sent    = countElementsInTable('glpi_queuednotifications', ['is_deleted' => 1]);
line("  oczekujacych (niewyslanych): $pending");
line("  wyslanych/zamknietych:       $sent");

$stuck = $DB->request([
    'SELECT' => ['id', 'itemtype', 'items_id', 'recipient', 'name', 'sent_try', 'create_time'],
    'FROM'   => 'glpi_queuednotifications',
    'WHERE'  => ['is_deleted' => 0, 'sent_try' => ['>', 0]],
    'ORDER'  => 'id DESC',
    'LIMIT'  => 10,
]);
foreach ($stuck as $row) {
    printf(
        "  ZACIETA id=%d prob=%d do=%s temat=%s\n",
        $row['id'],
        $row['sent_try'],
        $row['recipient'],
        $row['name'],
    );
    $problems[] = 'Wiadomosc zacieta w kolejce (id=' . $row['id'] . ', prob=' . $row['sent_try']
        . ') - problem z wysylka SMTP, nie z generowaniem';
}

$last_order_mail = $DB->request([
    'SELECT' => ['id', 'name', 'recipient', 'create_time', 'sent_time'],
    'FROM'   => 'glpi_queuednotifications',
    'WHERE'  => ['itemtype' => 'PluginOrderOrder'],
    'ORDER'  => 'id DESC',
    'LIMIT'  => 3,
]);
if (count($last_order_mail) === 0) {
    $notes[] = 'W kolejce nie ma ZADNEJ wiadomosci dotyczacej zamowien - patrz punkt 2 (odbiorcy).';
} else {
    line('  ostatnie wiadomosci o zamowieniach:');
    foreach ($last_order_mail as $row) {
        printf(
            "    id=%d utworzona=%s wyslana=%s do=%s\n",
            $row['id'],
            $row['create_time'],
            $row['sent_time'] ?? 'NIE',
            $row['recipient'],
        );
    }
}
line();

// --- 5. Zadania automatyczne ----------------------------------------------
line('5. Zadania automatyczne');
foreach (
    [
        ['QueuedNotification', 'queuednotification', 'wysylka maili z kolejki'],
        ['PluginOrderOrder', 'notInvoicedReminder', 'przypomnienia o niezafakturowanych'],
    ] as [$itemtype, $name, $desc]
) {
    $task = new CronTask();
    if (!$task->getFromDBbyName($itemtype, $name)) {
        check("Zadanie $name ($desc)", false, 'nie jest zarejestrowane');
        continue;
    }
    $state_label = match ((int) $task->fields['state']) {
        CronTask::STATE_DISABLE => 'WYLACZONE',
        CronTask::STATE_WAITING => 'oczekuje',
        CronTask::STATE_RUNNING => 'w trakcie',
        default                 => (string) $task->fields['state'],
    };
    printf(
        "  %-22s stan=%-10s ostatnie uruchomienie=%s\n",
        $name,
        $state_label,
        $task->fields['lastrun'] ?? 'NIGDY',
    );
    if ((int) $task->fields['state'] === CronTask::STATE_DISABLE) {
        $problems[] = "Zadanie $name jest wylaczone ($desc)";
    }
    if (empty($task->fields['lastrun'])) {
        $problems[] = "Zadanie $name nigdy sie nie uruchomilo - czy cron GLPI dziala?";
    }
}
line();

// --- 6. Naglowek graficzny -------------------------------------------------
line('6. Naglowek graficzny wiadomosci (dodatek wtyczki)');
$config   = PluginOrderConfig::getConfig(true);
$filename = (string) ($config->fields['mail_header_filename'] ?? '');
if ($filename === '') {
    line('  naglowek nieustawiony - hook nie modyfikuje zadnej wiadomosci');
} else {
    $path = $config->getMailHeaderPath();
    check('Plik naglowka istnieje i jest czytelny', $path !== null, $filename, false);
    if ($path !== null) {
        line('  sciezka: ' . $path);
        line('  URL w mailu: ' . (string) $config->getMailHeaderUrl());
        $notes[] = 'Naglowek jest wstrzykiwany TYLKO do wiadomosci o itemtype=PluginOrderOrder '
            . 'i tylko gdy tresc HTML nie jest pusta; nie moze zablokowac powstania wiadomosci '
            . '(cala operacja jest w try/catch).';
    } else {
        $notes[] = 'Nazwa pliku naglowka jest w bazie, ale pliku nie da sie odczytac - '
            . 'maile wyjda BEZ naglowka, poza tym dzialaja normalnie.';
    }
}
line();

// --- 7. Proba na konkretnym zamowieniu ------------------------------------
if ($order_id !== null) {
    line("7. Skonfigurowani odbiorcy wobec danych zamowienia $order_id");
    $order = new PluginOrderOrder();
    if (!$order->getFromDB($order_id)) {
        check("Zamowienie $order_id istnieje", false);
    } else {
        $target_labels = [
            PluginOrderNotificationTargetOrder::AUTHOR                    => 'Autor zamowienia',
            PluginOrderNotificationTargetOrder::AUTHOR_GROUP              => 'Grupa autora',
            PluginOrderNotificationTargetOrder::DELIVERY_USER             => 'Odbiorca dostawy',
            PluginOrderNotificationTargetOrder::DELIVERY_GROUP            => 'Grupa odbiorcy',
            PluginOrderNotificationTargetOrder::SUPERVISOR_AUTHOR_GROUP   => 'Przelozony grupy autora',
            PluginOrderNotificationTargetOrder::SUPERVISOR_DELIVERY_GROUP => 'Przelozony grupy odbiorcy',
            PluginOrderNotificationTargetOrder::SUPPLIER                  => 'Dostawca',
            PluginOrderNotificationTargetOrder::CONTACT                   => 'Kontakt',
            PluginOrderNotificationTargetOrder::REMINDER_RECIPIENTS       => 'Dodatkowe adresy z konfiguracji wtyczki',
        ];

        /**
         * Does this user exist and can GLPI actually mail them?
         */
        $describe_user = static function (int $users_id): string {
            global $DB;

            if ($users_id <= 0) {
                return 'NIEUSTAWIONY w zamowieniu';
            }
            $user = new User();
            if (!$user->getFromDB($users_id)) {
                return "uzytkownik id=$users_id nie istnieje";
            }
            $emails = [];
            foreach ($DB->request(['FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => $users_id]]) as $row) {
                $emails[] = $row['email'];
            }

            return $emails === []
                ? $user->fields['name'] . ' - BRAK ADRESU E-MAIL'
                : $user->fields['name'] . ' <' . implode(', ', $emails) . '>';
        };

        $rows = $DB->request([
            'SELECT'    => [
                'glpi_notifications.event',
                'glpi_notificationtargets.items_id',
                'glpi_notificationtargets.type',
            ],
            'FROM'      => 'glpi_notificationtargets',
            'INNER JOIN' => [
                'glpi_notifications' => [
                    'FKEY' => [
                        'glpi_notificationtargets' => 'notifications_id',
                        'glpi_notifications'       => 'id',
                    ],
                ],
            ],
            'WHERE'     => ['glpi_notifications.itemtype' => 'PluginOrderOrder'],
            'ORDER'     => 'glpi_notifications.event',
        ]);

        foreach ($rows as $row) {
            // The special items_id constants only mean anything for USER_TYPE
            // rows: a profile or group target reuses items_id as a plain
            // profile/group id, so labelling those with the constants would
            // misreport what the administrator configured.
            if ((int) $row['type'] !== Notification::USER_TYPE) {
                [$label, $resolved] = match ((int) $row['type']) {
                    Notification::PROFILE_TYPE => [
                        'Profil #' . $row['items_id'],
                        '(kazdy uzytkownik tego profilu z adresem e-mail)',
                    ],
                    Notification::GROUP_TYPE => [
                        'Grupa #' . $row['items_id'],
                        '(czlonkowie grupy z adresem e-mail)',
                    ],
                    default => ['cel typu #' . $row['type'] . ' / #' . $row['items_id'], ''],
                };
                printf("  %-16s -> %-28s %s\n", $row['event'], $label, $resolved);
                continue;
            }

            $label = $target_labels[$row['items_id']] ?? ('cel #' . $row['items_id']);
            $resolved = match ((int) $row['items_id']) {
                PluginOrderNotificationTargetOrder::AUTHOR
                    => $describe_user((int) $order->fields['users_id']),
                PluginOrderNotificationTargetOrder::DELIVERY_USER
                    => $describe_user((int) ($order->fields['users_id_delivery'] ?? 0)),
                PluginOrderNotificationTargetOrder::REMINDER_RECIPIENTS
                    => ($config->getNotInvoicedReminderEmails() === []
                        ? 'BRAK adresow w konfiguracji wtyczki'
                        : implode(', ', $config->getNotInvoicedReminderEmails())),
                default => '(rozwiazywane dynamicznie: grupy / dostawca / kontakt)',
            };
            printf("  %-16s -> %-28s %s\n", $row['event'], $label, $resolved);
            if (str_contains($resolved, 'BRAK ADRESU') || str_contains($resolved, 'NIEUSTAWIONY')) {
                $problems[] = "Zdarzenie {$row['event']} kieruje na \"$label\", ale: $resolved";
            }
        }
    }
    line();
}

// --- Podsumowanie ----------------------------------------------------------
line('=== Podsumowanie ===');
if ($problems === []) {
    line('Nie wykryto blokad w lancuchu powiadomien.');
} else {
    line('Znalezione problemy (w kolejnosci waznosci):');
    foreach ($problems as $i => $problem) {
        line('  ' . ($i + 1) . '. ' . $problem);
    }
}
foreach ($notes as $note) {
    line('Uwaga: ' . $note);
}
line();
