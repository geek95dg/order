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

header("Content-Type: text/html; charset=UTF-8");

Html::header_nocache();

Session::checkCentralAccess();

$PluginOrderReference = new PluginOrderReference();

if ($_POST["itemtype"]) {
    switch ($_POST["field"]) {
        case "types_id":
            $type_class = PluginOrderReference::getTypeClassForItemtype($_POST["itemtype"]);
            if ($type_class !== null) {
                Dropdown::show($type_class, ['name' => "types_id"]);
            }

            break;
        case "models_id":
            $model_class = PluginOrderReference::getModelClassForItemtype($_POST["itemtype"]);
            if ($model_class !== null) {
                Dropdown::show($model_class, ['name' => "models_id"]);
            } else {
                return "";
            }

            break;
        case "templates_id":
            $item = getItemForItemtype($_POST['itemtype']);
            if ($item->maybeTemplate()) {
                $table = getTableForItemType($_POST["itemtype"]);
                $PluginOrderReference->dropdownTemplate("templates_id", $_POST["entity_restrict"], $table, 0, $_POST["itemtype"]);
            } else {
                return "";
            }

            break;
    }
} else {
    return '';
}
