<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Official Ministry of Youth and Sports PDF & Excel Report Exporter.
 * Uses Dompdf and PhpSpreadsheet when installed via Composer, with clean built-in fallbacks
 * so exports remain functional in every environment.
 */
final class ReportExportService
{
    /**
     * Render official Ministry-branded HTML report to PDF (Dompdf) or print-ready HTML fallback.
     */
    public function outputPdf(string $title, string $bodyHtml, string $filename, array $meta = [], bool $returnBinary = false): ?string
    {
        $fullHtml = $this->wrapOfficialReportHtml($title, $bodyHtml, $meta);

        if (class_exists(\Dompdf\Dompdf::class)) {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($fullHtml);
            $dompdf->setPaper('A4', $meta['orientation'] ?? 'portrait');
            $dompdf->render();
            $pdfOutput = $dompdf->output();

            if ($returnBinary) {
                return $pdfOutput;
            }

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $this->safeFilename($filename, 'pdf') . '"');
            echo $pdfOutput;
            exit;
        }

        // Fallback when Dompdf is not yet installed via composer install:
        // Serves official Ministry print-optimized document with automatic Save-as-PDF button.
        if ($returnBinary) {
            return $fullHtml;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $fullHtml;
        exit;
    }

    /**
     * Export tabular recruitment data to Excel (.xlsx via PhpSpreadsheet, or native OOXML/SpreadsheetML fallback).
     */
    public function outputExcel(
        string $reportTitle,
        array $metadataLines,
        array $headers,
        array $rows,
        string $filename,
        bool $returnBinary = false
    ): ?string {
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('MYS Report');

            $rowNum = 1;
            $sheet->setCellValue("A{$rowNum}", 'REPUBLIC OF LIBERIA — MINISTRY OF YOUTH AND SPORTS');
            $sheet->getStyle("A{$rowNum}")->getFont()->setBold(true)->setSize(13);
            $rowNum++;

            $sheet->setCellValue("A{$rowNum}", $reportTitle);
            $sheet->getStyle("A{$rowNum}")->getFont()->setBold(true)->setSize(11);
            $rowNum++;

            foreach ($metadataLines as $label => $val) {
                $sheet->setCellValue("A{$rowNum}", $label . ':');
                $sheet->setCellValue("B{$rowNum}", (string)$val);
                $rowNum++;
            }
            $rowNum++;

            // Column headers
            $colIndex = 1;
            foreach ($headers as $header) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                $sheet->setCellValue("{$colLetter}{$rowNum}", $header);
                $sheet->getStyle("{$colLetter}{$rowNum}")->getFont()->setBold(true);
                $sheet->getColumnDimension($colLetter)->setAutoSize(true);
                $colIndex++;
            }
            $rowNum++;

            foreach ($rows as $dataRow) {
                $colIndex = 1;
                foreach ($dataRow as $cell) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                    $sheet->setCellValue("{$colLetter}{$rowNum}", $cell);
                    $colIndex++;
                }
                $rowNum++;
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $binary = (string)ob_get_clean();

            if ($returnBinary) {
                return $binary;
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $this->safeFilename($filename, 'xlsx') . '"');
            echo $binary;
            exit;
        }

        // Native Excel SpreadsheetML (.xls) fallback when Composer PhpSpreadsheet is not yet installed
        $xml = $this->buildSpreadsheetMl($reportTitle, $metadataLines, $headers, $rows);
        if ($returnBinary) {
            return $xml;
        }

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $this->safeFilename($filename, 'xls') . '"');
        echo $xml;
        exit;
    }

    public function wrapOfficialReportHtml(string $title, string $bodyHtml, array $meta = []): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $generatedAt = date('F j, Y \a\t H:i T');
        $dompdfAvailable = class_exists(\Dompdf\Dompdf::class);
        $printToolbar = $dompdfAvailable ? '' : '
            <div class="no-print" style="background:#0B2545;color:#fff;padding:10px 20px;display:flex;justify-content:space-between;align-items:center;font-family:Arial,sans-serif;">
                <span>Official Report Preview — Click Print / Save as PDF (Install Composer Dompdf for direct PDF download)</span>
                <button onclick="window.print()" style="background:#C9A227;color:#0B2545;border:none;padding:7px 16px;font-weight:bold;border-radius:4px;cursor:pointer;">Print / Save PDF</button>
            </div>';

        $metaHtml = '';
        if (!empty($meta['details']) && is_array($meta['details'])) {
            $metaHtml .= '<table class="meta-table"><tbody>';
            foreach ($meta['details'] as $k => $v) {
                $metaHtml .= '<tr><th>' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            $metaHtml .= '</tbody></table>';
        }

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $ministry = htmlspecialchars($config['ministry'], ENT_QUOTES, 'UTF-8');
        $republic = htmlspecialchars($config['republic'], ENT_QUOTES, 'UTF-8');
        $address = htmlspecialchars($config['address'], ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$safeTitle} — {$ministry}</title>
<style>
    @page { margin: 18mm 14mm; }
    body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; color: #1b2430; font-size: 11px; line-height: 1.45; margin: 0; }
    .container { padding: 14px 20px; }
    .letterhead { border-bottom: 3px solid #0B2545; padding-bottom: 10px; margin-bottom: 14px; text-align: center; }
    .letterhead .republic { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: #BF0A30; font-weight: bold; }
    .letterhead .ministry { font-size: 16px; font-weight: bold; color: #0B2545; margin: 2px 0; text-transform: uppercase; }
    .letterhead .address { font-size: 9.5px; color: #556270; }
    .report-title { background: #0B2545; color: #ffffff; padding: 7px 12px; font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
    .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .meta-table th { width: 24%; text-align: left; background: #f2f5f9; color: #0B2545; padding: 5px 8px; border: 1px solid #d5dde7; font-size: 10px; }
    .meta-table td { padding: 5px 8px; border: 1px solid #d5dde7; font-size: 10px; }
    table.data-table { width: 100%; border-collapse: collapse; margin: 10px 0 16px 0; }
    table.data-table th { background: #143662; color: #fff; text-align: left; padding: 6px 7px; border: 1px solid #143662; font-size: 10px; }
    table.data-table td { padding: 5px 7px; border: 1px solid #cfd8e3; font-size: 10px; vertical-align: top; }
    table.data-table tr:nth-child(even) td { background: #f8fafc; }
    .badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: bold; }
    .badge-physical { background: #e8f1fb; color: #0B2545; }
    .badge-virtual { background: #ede9fe; color: #4c1d95; }
    .section-heading { color: #0B2545; font-size: 12px; font-weight: bold; border-left: 4px solid #BF0A30; padding-left: 8px; margin: 14px 0 8px 0; }
    .signatures { width: 100%; border-collapse: collapse; margin-top: 28px; page-break-inside: avoid; }
    .signatures td { width: 33.3%; padding: 14px 12px 4px 12px; vertical-align: bottom; text-align: center; }
    .sig-line { border-top: 1px solid #1b2430; padding-top: 5px; font-size: 10px; font-weight: bold; color: #0B2545; }
    .sig-sub { font-size: 9px; color: #556270; }
    .footer-note { margin-top: 18px; border-top: 1px solid #d5dde7; padding-top: 6px; font-size: 9px; color: #64748b; display: flex; justify-content: space-between; }
    @media print { .no-print { display: none !important; } .container { padding: 0; } }
</style>
</head>
<body>
{$printToolbar}
<div class="container">
    <div class="letterhead">
        <div class="republic">{$republic}</div>
        <div class="ministry">{$ministry}</div>
        <div class="address">{$address}</div>
    </div>
    <div class="report-title">{$safeTitle}</div>
    {$metaHtml}
    {$bodyHtml}
    <table class="signatures">
        <tr>
            <td>
                <div class="sig-line">Interview Panel Chairperson</div>
                <div class="sig-sub">Signature &amp; Date</div>
            </td>
            <td>
                <div class="sig-line">Director of Human Resources</div>
                <div class="sig-sub">Signature &amp; Date</div>
            </td>
            <td>
                <div class="sig-line">Deputy Minister for Administration</div>
                <div class="sig-sub">Authorized Approval &amp; Seal</div>
            </td>
        </tr>
    </table>
    <div class="footer-note">
        <span>Generated by MYS Interview Panel Management &amp; Scoring System</span>
        <span>Generated on: {$generatedAt}</span>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function buildSpreadsheetMl(string $title, array $metadataLines, array $headers, array $rows): string
    {
        $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xmlRows = '';
        $xmlRows .= '<Row><Cell ss:StyleID="Title"><Data ss:Type="String">REPUBLIC OF LIBERIA — MINISTRY OF YOUTH AND SPORTS</Data></Cell></Row>';
        $xmlRows .= '<Row><Cell ss:StyleID="SubTitle"><Data ss:Type="String">' . $esc($title) . '</Data></Cell></Row>';
        foreach ($metadataLines as $k => $v) {
            $xmlRows .= '<Row><Cell ss:StyleID="MetaKey"><Data ss:Type="String">' . $esc($k) . '</Data></Cell><Cell><Data ss:Type="String">' . $esc($v) . '</Data></Cell></Row>';
        }
        $xmlRows .= '<Row/>';

        $xmlRows .= '<Row>';
        foreach ($headers as $h) {
            $xmlRows .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . $esc($h) . '</Data></Cell>';
        }
        $xmlRows .= '</Row>';

        foreach ($rows as $r) {
            $xmlRows .= '<Row>';
            foreach ($r as $cell) {
                $type = (is_int($cell) || is_float($cell)) ? 'Number' : 'String';
                $xmlRows .= '<Cell><Data ss:Type="' . $type . '">' . $esc($cell) . '</Data></Cell>';
            }
            $xmlRows .= '</Row>';
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Title"><Font ss:Bold="1" ss:Size="13" ss:Color="#0B2545"/></Style>
  <Style ss:ID="SubTitle"><Font ss:Bold="1" ss:Size="11" ss:Color="#BF0A30"/></Style>
  <Style ss:ID="MetaKey"><Font ss:Bold="1"/></Style>
  <Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#0B2545" ss:Pattern="Solid"/></Style>
 </Styles>
 <Worksheet ss:Name="MYS Report">
  <Table>
   {$xmlRows}
  </Table>
 </Worksheet>
</Workbook>
XML;
    }

    private function safeFilename(string $base, string $ext): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '_', $base) ?: 'mys_report';
        return $clean . '.' . $ext;
    }
}
