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
 * Generates OT (fixed assets protocol) PDF documents from order data.
 */
class PluginOrderOt
{
    /**
     * Show the Cost Center input sub-form for the massive action popup.
     */
    public static function showMassiveActionSubForm(): void
    {
        echo "<label for='cost_center'>" . __s("Cost Center", "order") . " (MPK):&nbsp;</label>";
        echo Html::input('cost_center', [
            'id'    => 'cost_center',
            'value' => '',
            'size'  => 20,
        ]);
        echo "<br><br>";
        echo Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);
    }


    /**
     * Full orchestration: generate HTML -> PDF -> save as Document -> return document ID.
     *
     * @param int    $order_id    The order ID
     * @param string $cost_center Cost Center / MPK value entered by user
     * @return int|false Document ID on success, false on failure
     */
    public function processAction(int $order_id, string $cost_center)
    {
        $order = new PluginOrderOrder();
        if (!$order->getFromDB($order_id)) {
            return false;
        }

        $html = $this->generateOtHtml($order, $cost_center);
        $num_order = $order->fields['num_order'] ?: $order_id;
        $base_name = 'OT_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $num_order);

        $pdf_path = $this->generatePdf($html, $base_name);
        if ($pdf_path === false) {
            return false;
        }

        $is_pdf  = str_ends_with($pdf_path, '.pdf');
        $ext     = $is_pdf ? 'pdf' : 'html';
        $mime    = $is_pdf ? 'application/pdf' : 'text/html';

        $doc_id = $this->saveAsDocument($order, $pdf_path, $base_name . '.' . $ext, $mime);

        return $doc_id;
    }


    /**
     * Build the full HTML document matching the OT.xlsx template layout.
     *
     * @param PluginOrderOrder $order       The order object (already loaded)
     * @param string           $cost_center Cost Center value
     * @return string Complete HTML document
     */
    public function generateOtHtml(PluginOrderOrder $order, string $cost_center): string
    {
        /** @var DBmysql $DB */
        global $DB;

        $order_id  = $order->getID();
        $num_order = $order->fields['num_order'] ?? '';

        // Get supplier name
        $supplier_name = '';
        $supplier = new Supplier();
        if ($supplier->getFromDB($order->fields['suppliers_id'])) {
            $supplier_name = $supplier->fields['name'];
        }

        // Query delivered items (items_id != 0 means delivered and linked to an asset)
        $items_result = $DB->request([
            'FROM'  => 'glpi_plugin_order_orders_items',
            'WHERE' => [
                'plugin_order_orders_id' => $order_id,
                'items_id'              => ['!=', 0],
            ],
            'ORDER' => 'id ASC',
        ]);

        $rows = [];
        $total_value = 0.0;

        foreach ($items_result as $item_data) {
            $asset_name   = '';
            $asset_serial = '';
            $itemtype     = $item_data['itemtype'] ?? '';
            $items_id     = (int) ($item_data['items_id'] ?? 0);

            if ($itemtype && $items_id > 0) {
                $asset = getItemForItemtype($itemtype);
                if ($asset !== false && $asset->getFromDB($items_id)) {
                    $asset_name   = $asset->fields['name'] ?? '';
                    $asset_serial = $asset->fields['serial'] ?? '';
                }
            }

            $price = (float) ($item_data['price_taxfree'] ?? 0);
            $total_value += $price;

            $delivery_date = '';
            if (!empty($item_data['delivery_date'])) {
                $delivery_date = Html::convDate($item_data['delivery_date']);
            }

            $rows[] = [
                'name'          => htmlspecialchars($asset_name, ENT_QUOTES, 'UTF-8'),
                'serial'        => htmlspecialchars($asset_serial, ENT_QUOTES, 'UTF-8'),
                'price'         => number_format($price, 2, ',', ' '),
                'delivery_date' => htmlspecialchars($delivery_date, ENT_QUOTES, 'UTF-8'),
            ];
        }

        $supplier_esc   = htmlspecialchars($supplier_name, ENT_QUOTES, 'UTF-8');
        $cost_center_esc = htmlspecialchars($cost_center, ENT_QUOTES, 'UTF-8');
        $num_order_esc  = htmlspecialchars($num_order, ENT_QUOTES, 'UTF-8');
        $total_formatted = number_format($total_value, 2, ',', ' ');

        // Build item rows HTML
        $items_html = '';
        $pos = 1;
        foreach ($rows as $row) {
            $items_html .= "<tr>
                <td style='border:1px solid #000;text-align:center;padding:3px;'>{$pos}</td>
                <td style='border:1px solid #000;text-align:center;padding:3px;'>1</td>
                <td style='border:1px solid #000;padding:3px;'>{$row['name']}</td>
                <td style='border:1px solid #000;padding:3px;'>{$row['serial']}</td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;text-align:right;padding:3px;'>{$row['price']}</td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'>{$cost_center_esc}</td>
                <td style='border:1px solid #000;padding:3px;'>{$num_order_esc}</td>
                <td style='border:1px solid #000;padding:3px;'>{$row['delivery_date']}</td>
                <td style='border:1px solid #000;padding:3px;'></td>
            </tr>\n";
            $pos++;
        }

        // Fill empty rows up to 20 lines to match the template
        for ($i = $pos; $i <= 20; $i++) {
            $items_html .= "<tr>
                <td style='border:1px solid #000;text-align:center;padding:3px;'>{$i}</td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
                <td style='border:1px solid #000;padding:3px;'></td>
            </tr>\n";
        }

        $th_style = "border:1px solid #000;padding:4px;text-align:center;font-weight:bold;font-size:9px;background:#f0f0f0;";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>OT - {$num_order_esc}</title>
<style>
    @page { size: A4 landscape; margin: 10mm 12mm; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; margin: 0; padding: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { font-size: 9px; }
</style>
</head>
<body>

<table>
    <tr>
        <td colspan="11" style="text-align:center;font-size:22px;font-weight:bold;padding:8px 0 2px 0;">OT</td>
    </tr>
    <tr>
        <td colspan="11" style="text-align:center;font-size:11px;padding:2px 0;">
            Potwierdzenie w&#322;&#261;czenia do u&#380;ytku / Lokalizacja &#347;rodk&oacute;w trwa&#322;ych
        </td>
    </tr>
    <tr>
        <td colspan="11" style="text-align:center;font-size:9px;font-style:italic;padding:0 0 10px 0;">
            (Nachweis der Inbetriebnahme / Verteilung der Sachanlagen)
        </td>
    </tr>
</table>

<table>
    <tr>
        <td style="width:40%;padding:4px 0;">
            <strong>Numer faktury:</strong> _______________
        </td>
        <td style="width:60%;padding:4px 0;">
            <strong>Dostawca / Producent:</strong> {$supplier_esc}
        </td>
    </tr>
</table>

<br>

<table>
    <thead>
        <tr>
            <th style="{$th_style}width:4%;">Poz</th>
            <th style="{$th_style}width:5%;">Ilo&#347;&#263;/<br>Menge</th>
            <th style="{$th_style}width:18%;">Nazwa &#347;rodka trwa&#322;ego /<br>Bezeichnung</th>
            <th style="{$th_style}width:12%;">Nr seryjny /<br>Seriennummer</th>
            <th style="{$th_style}width:10%;">Nr &#347;rodka trwa&#322;ego /<br>Anlagennumm.</th>
            <th style="{$th_style}width:10%;">Warto&#347;&#263; /<br>Betrag</th>
            <th style="{$th_style}width:8%;">Lokaliz. /<br>Standort</th>
            <th style="{$th_style}width:9%;">Cost<br>Center</th>
            <th style="{$th_style}width:8%;">Order</th>
            <th style="{$th_style}width:10%;">Data w&#322;&#261;cz. do u&#380;ytku /<br>Datum Inbetriebnahme</th>
            <th style="{$th_style}width:10%;">Data zdep. w magazynie /<br>Datum ins Lager</th>
        </tr>
    </thead>
    <tbody>
        {$items_html}
        <tr>
            <td colspan="5" style="border:1px solid #000;text-align:right;padding:4px;font-weight:bold;">SUMA / Summe:</td>
            <td style="border:1px solid #000;text-align:right;padding:4px;font-weight:bold;">{$total_formatted}</td>
            <td colspan="5" style="border:1px solid #000;padding:3px;"></td>
        </tr>
    </tbody>
</table>

<br>
<table>
    <tr><td style="padding:15px 0 5px 40px;">____________________________</td></tr>
    <tr><td style="padding:0 0 0 60px;font-size:9px;">czytelny podpis</td></tr>
</table>

<br>
<table>
    <tr><td style="padding:5px 0;"><strong>Uwagi:</strong></td></tr>
    <tr><td style="border-bottom:1px solid #999;padding:10px 0;">&nbsp;</td></tr>
    <tr><td style="border-bottom:1px solid #999;padding:10px 0;">&nbsp;</td></tr>
</table>

<br><br>

<table>
    <tr>
        <td colspan="11" style="font-size:10px;font-weight:bold;padding:8px 0 4px 0;">
            Zmiany lokalizacji &#347;rodk&oacute;w trwa&#322;ych / (Verschiebung von Anlagenverm&ouml;gen)
        </td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th style="{$th_style}width:4%;">Poz</th>
            <th style="{$th_style}width:5%;">Ilo&#347;&#263;/<br>Menge</th>
            <th style="{$th_style}width:18%;">Nazwa &#347;rodka trwa&#322;ego /<br>Bezeichnung</th>
            <th style="{$th_style}width:12%;">Nr seryjny /<br>Seriennummer</th>
            <th style="{$th_style}width:10%;">Nr &#347;rodka trwa&#322;ego /<br>Anlagennumm.</th>
            <th style="{$th_style}width:10%;">Lokaliz. /<br>Standort</th>
            <th style="{$th_style}width:9%;">Cost<br>Center</th>
            <th style="{$th_style}width:8%;">Order</th>
            <th style="{$th_style}width:12%;">Data przes. /<br>Datum von Verschiebung</th>
            <th style="{$th_style}width:12%;">Data w&#322;&#261;cz. do u&#380;ytku /<br>Datum Inbetriebnahme</th>
        </tr>
    </thead>
    <tbody>
HTML;

        // 10 empty rows for the relocation section
        for ($i = 1; $i <= 10; $i++) {
            $html .= "<tr>";
            for ($c = 0; $c < 10; $c++) {
                $html .= "<td style='border:1px solid #000;padding:3px;'>&nbsp;</td>";
            }
            $html .= "</tr>\n";
        }

        $html .= <<<HTML
    </tbody>
</table>

<br>
<table>
    <tr><td style="padding:15px 0 5px 40px;">____________________________</td></tr>
    <tr><td style="padding:0 0 0 60px;font-size:9px;">czytelny podpis</td></tr>
    <tr>
        <td style="padding:5px 0 0 200px;font-size:8px;font-style:italic;">
            * nach Bearbeitung durch Rechnungspr&uuml;fer Kopie an LZ
        </td>
    </tr>
</table>

</body>
</html>
HTML;

        return $html;
    }


    /**
     * Generate PDF from HTML using fallback chain: wkhtmltopdf -> Chromium -> mPDF -> HTML file.
     *
     * @param string $html      Full HTML document
     * @param string $base_name Base filename (without extension)
     * @return string|false Path to generated file, or false on failure
     */
    public function generatePdf(string $html, string $base_name)
    {
        $tmp_dir   = GLPI_TMP_DIR;
        $html_path = $tmp_dir . '/' . $base_name . '_' . uniqid() . '.html';
        $pdf_path  = $tmp_dir . '/' . $base_name . '_' . uniqid() . '.pdf';

        file_put_contents($html_path, $html);

        // 1. Try wkhtmltopdf
        $wk_path = $this->findBinary('wkhtmltopdf');
        if ($wk_path) {
            $cmd = sprintf(
                '%s --quiet --page-size A4 --orientation Landscape --encoding utf-8 --margin-top 10 --margin-bottom 10 --margin-left 12 --margin-right 12 %s %s 2>&1',
                escapeshellarg($wk_path),
                escapeshellarg($html_path),
                escapeshellarg($pdf_path),
            );
            exec($cmd, $output, $exit_code);
            @unlink($html_path);
            if ($exit_code === 0 && file_exists($pdf_path)) {
                return $pdf_path;
            }
        }

        // 2. Try Chromium headless
        $chrome_path = $this->findBinary('chromium-browser') ?: $this->findBinary('chromium') ?: $this->findBinary('google-chrome');
        if ($chrome_path) {
            $cmd = sprintf(
                '%s --headless --disable-gpu --no-sandbox --print-to-pdf=%s --no-pdf-header-footer file://%s 2>&1',
                escapeshellarg($chrome_path),
                escapeshellarg($pdf_path),
                escapeshellarg($html_path),
            );
            exec($cmd, $output, $exit_code);
            @unlink($html_path);
            if ($exit_code === 0 && file_exists($pdf_path)) {
                return $pdf_path;
            }
        }

        // 3. Try mPDF
        if (class_exists('\\Mpdf\\Mpdf')) {
            try {
                $mpdf = new \Mpdf\Mpdf([
                    'mode'          => 'utf-8',
                    'format'        => 'A4-L',
                    'margin_left'   => 12,
                    'margin_right'  => 12,
                    'margin_top'    => 10,
                    'margin_bottom' => 10,
                    'tempDir'       => $tmp_dir,
                ]);
                // Adapt for mPDF limitations
                $adapted_html = str_replace("'DejaVu Sans'", "'dejavusans'", $html);
                $mpdf->WriteHTML($adapted_html);
                $mpdf->Output($pdf_path, \Mpdf\Output\Destination::FILE);
                @unlink($html_path);
                if (file_exists($pdf_path)) {
                    return $pdf_path;
                }
            } catch (\Throwable $e) {
                // mPDF failed, continue to fallback
            }
        }

        // 4. Fallback: save as HTML
        @unlink($pdf_path);
        // Return the HTML file path directly
        return $html_path;
    }


    /**
     * Find a binary in common system paths.
     *
     * @param string $name Binary name
     * @return string|null Full path if found, null otherwise
     */
    private function findBinary(string $name): ?string
    {
        $search_paths = [
            '/usr/bin/',
            '/usr/local/bin/',
            '/snap/bin/',
        ];

        foreach ($search_paths as $dir) {
            $path = $dir . $name;
            if (is_executable($path)) {
                return $path;
            }
        }

        // Try `which` as last resort
        $result = trim((string) shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($result && is_executable($result)) {
            return $result;
        }

        return null;
    }


    /**
     * Save the generated file as a GLPI Document linked to the order.
     *
     * @param PluginOrderOrder $order    The order (already loaded)
     * @param string           $filepath Absolute path to the generated file
     * @param string           $filename Document filename (e.g. "OT_PO123.pdf")
     * @param string           $mime     MIME type
     * @return int|false Document ID on success, false on failure
     */
    public function saveAsDocument(PluginOrderOrder $order, string $filepath, string $filename, string $mime)
    {
        if (!file_exists($filepath)) {
            return false;
        }

        // Move file to GLPI document storage
        $doc_dir = GLPI_DOC_DIR . '/_plugins/order/ot/';
        @mkdir($doc_dir, 0755, true);

        $dest_path = $doc_dir . $filename;
        // If file already exists, add unique suffix
        if (file_exists($dest_path)) {
            $info = pathinfo($filename);
            $dest_path = $doc_dir . $info['filename'] . '_' . date('YmdHis') . '.' . $info['extension'];
            $filename  = basename($dest_path);
        }

        if (!rename($filepath, $dest_path)) {
            if (!copy($filepath, $dest_path)) {
                return false;
            }
            @unlink($filepath);
        }

        // Relative path from GLPI_DOC_DIR
        $relative_path = '_plugins/order/ot/' . $filename;

        // Create GLPI Document record
        $doc = new Document();
        $doc_id = $doc->add([
            'name'          => $filename,
            'filename'      => $filename,
            'filepath'      => $relative_path,
            'mime'          => $mime,
            'entities_id'   => $order->fields['entities_id'],
            'is_recursive'  => $order->fields['is_recursive'] ?? 0,
        ]);

        if (!$doc_id) {
            return false;
        }

        // Link document to the order
        $doc_item = new Document_Item();
        $doc_item->add([
            'documents_id' => $doc_id,
            'itemtype'     => PluginOrderOrder::class,
            'items_id'     => $order->getID(),
            'entities_id'  => $order->fields['entities_id'],
        ]);

        return $doc_id;
    }


    /**
     * Stream a document file as a download response.
     *
     * @param int $doc_id GLPI Document ID
     * @return void
     */
    public static function downloadDocument(int $doc_id): void
    {
        $doc = new Document();
        if (!$doc->getFromDB($doc_id)) {
            return;
        }

        $filepath = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        if (!file_exists($filepath)) {
            return;
        }

        $filename = $doc->fields['filename'];
        $mime     = $doc->fields['mime'] ?: 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($filepath);
    }
}
