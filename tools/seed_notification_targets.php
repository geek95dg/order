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
 * Give the plugin's notifications a recipient.
 *
 * The plugin creates its notifications active but WITHOUT recipients, so until
 * somebody picks one in the interface they can never be sent - silently. This
 * script fills that gap for the notifications that currently have no recipient
 * at all; it never touches a notification that already has one, so a recipient
 * an administrator removed on purpose is only restored if they ask for it.
 *
 * Shows what it would do and changes nothing unless --apply is passed:
 *   php marketplace/order/tools/seed_notification_targets.php
 *   php marketplace/order/tools/seed_notification_targets.php --apply
 *   php marketplace/order/tools/seed_notification_targets.php --apply --with-delivery
 */

use Glpi\Kernel\Kernel;

function bail(string $title, array $hints): never
{
    fwrite(STDERR, "\n" . $title . "\n");
    foreach ($hints as $hint) {
        fwrite(STDERR, '  - ' . $hint . "\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

$missing = array_values(array_filter(
    ['mysqli', 'json', 'mbstring'],
    static fn(string $ext): bool => !extension_loaded($ext),
));
if ($missing !== []) {
    bail(
        'This PHP binary (' . PHP_BINARY . ', ' . PHP_VERSION . ') is missing: ' . implode(', ', $missing),
        [
            'Use the same PHP that serves GLPI - the web server and the CLI often differ.',
            'for p in php php8.2 php8.3 php8.4; do printf "%s: " "$p";'
                . ' "$p" -r \'echo extension_loaded("mysqli") ? "ok" : "no mysqli";\' 2>/dev/null; echo; done',
        ],
    );
}

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
        'Pass it explicitly: --glpi=/var/www/html/glpi',
    ]);
}

chdir($root);
require_once $root . '/vendor/autoload.php';

try {
    (new Kernel())->boot();
} catch (\Throwable $e) {
    bail('GLPI could not start: ' . $e->getMessage(), [
        'Root used: ' . $root,
        'Run as the web server user, e.g. sudo -u www-data ...',
    ]);
}

$_SESSION['glpi_currenttime']          = date('Y-m-d H:i:s');
$_SESSION['glpiactive_entity']         = 0;
$_SESSION['glpiactiveentities']        = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiname']                  = 'cli_seed_targets';

/** @var DBmysql $DB */
global $DB;

$apply         = in_array('--apply', $argv, true);
$with_delivery = in_array('--with-delivery', $argv, true);

$wanted = [PluginOrderNotificationTargetOrder::AUTHOR => 'Autor zamowienia'];
if ($with_delivery) {
    $wanted[PluginOrderNotificationTargetOrder::DELIVERY_USER] = 'Odbiorca dostawy';
}

echo "\n=== Odbiorcy powiadomien wtyczki Order ===\n";
echo $apply ? "TRYB: zapis\n\n" : "TRYB: podglad (nic nie zostanie zmienione, dodaj --apply)\n\n";

$notifications = $DB->request([
    'FROM'  => 'glpi_notifications',
    'WHERE' => ['itemtype' => 'PluginOrderOrder'],
    'ORDER' => 'event',
]);

$added   = 0;
$skipped = 0;

foreach ($notifications as $notification) {
    $existing = countElementsInTable('glpi_notificationtargets', [
        'notifications_id' => $notification['id'],
    ]);

    if ($existing > 0) {
        printf("  %-16s ma juz %d odbiorcow - pomijam\n", $notification['event'], $existing);
        $skipped++;
        continue;
    }

    foreach ($wanted as $items_id => $label) {
        printf(
            "  %-16s %s odbiorca: %s\n",
            $notification['event'],
            $apply ? 'DODAJE' : 'dodalbym',
            $label,
        );

        if ($apply) {
            $target = new NotificationTarget();
            $ok = $target->add([
                'notifications_id' => $notification['id'],
                'items_id'         => $items_id,
                'type'             => Notification::USER_TYPE,
            ]);
            if (!$ok) {
                printf("      NIE UDALO SIE zapisac odbiorcy dla %s\n", $notification['event']);
                continue;
            }
        }
        $added++;
    }
}

echo "\n";
printf("Powiadomien pominietych (mialy juz odbiorcow): %d\n", $skipped);
printf("Odbiorcow %s: %d\n", $apply ? 'dodanych' : 'do dodania', $added);
if (!$apply && $added > 0) {
    echo "\nUruchom ponownie z --apply, aby zapisac zmiany.\n";
}
echo "\n";
