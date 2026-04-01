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

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

Session::checkLoginUser();

if (!PluginOrderOrder::canView()) {
    throw new AccessDeniedHttpException();
}

// Download a previously generated OT document
if (isset($_GET['download']) && isset($_GET['doc_id'])) {
    $doc_id = (int) $_GET['doc_id'];
    if ($doc_id > 0) {
        PluginOrderOt::downloadDocument($doc_id);
        exit;
    }
}
