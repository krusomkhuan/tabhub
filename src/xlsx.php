<?php
/** อ่าน/เขียนไฟล์ Excel (.xlsx) แบบเรียบง่าย โดยไม่ต้องใช้ส่วนขยาย zip ของ PHP */

function zip_pack(array $files): string {
  $locals = ''; $centrals = ''; $offset = 0;
  foreach ($files as $name => $raw) {
    $comp = gzdeflate($raw);
    $crc = crc32($raw);
    $locals .= pack('Vvvv', 0x04034b50, 20, 0x0800, 8) . pack('V', 0) . pack('VVV', $crc, strlen($comp), strlen($raw)) . pack('vv', strlen($name), 0) . $name . $comp;
    $centrals .= pack('Vvvvv', 0x02014b50, 20, 20, 0x0800, 8) . pack('V', 0) . pack('VVV', $crc, strlen($comp), strlen($raw)) . pack('vvvvv', strlen($name), 0, 0, 0, 0) . pack('V', 0) . pack('V', $offset) . $name;
    $offset += 30 + strlen($name) + strlen($comp);
  }
  $n = count($files);
  return $locals . $centrals . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($centrals), $offset, 0);
}

function zip_unpack(string $buf): array {
  $e = strlen($buf) - 22;
  while ($e >= 0 && substr($buf, $e, 4) !== "PK\x05\x06") $e--;
  if ($e < 0) throw new Exception('ไฟล์ไม่ใช่ .xlsx ที่ถูกต้อง');
  $n = unpack('v', $buf, $e + 10)[1];
  $p = unpack('V', $buf, $e + 16)[1];
  $out = [];
  for ($i = 0; $i < $n; $i++) {
    $method = unpack('v', $buf, $p + 10)[1];
    $csize = unpack('V', $buf, $p + 20)[1];
    $nl = unpack('v', $buf, $p + 28)[1]; $xl = unpack('v', $buf, $p + 30)[1]; $cl = unpack('v', $buf, $p + 32)[1];
    $lo = unpack('V', $buf, $p + 42)[1];
    $name = substr($buf, $p + 46, $nl);
    $ds = $lo + 30 + unpack('v', $buf, $lo + 26)[1] + unpack('v', $buf, $lo + 28)[1];
    $data = substr($buf, $ds, $csize);
    $out[$name] = $method === 8 ? gzinflate($data) : $data;
    $p += 46 + $nl + $xl + $cl;
  }
  return $out;
}

function xml_esc($s): string {
  $s = str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], (string)$s);
  return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
}
function xml_unesc($s): string { return html_entity_decode((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

function xlsx_col_name(int $i): string {
  $s = ''; $i++;
  while ($i) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = (int)floor(($i - 1) / 26); }
  return $s;
}
function xlsx_col_index(string $ref): int {
  preg_match('/^([A-Z]+)/', $ref, $m);
  $n = 0;
  foreach (str_split($m[1]) as $ch) $n = $n * 26 + ord($ch) - 64;
  return $n - 1;
}

/** สร้างไฟล์ .xlsx จากตาราง 2 มิติ (แถวแรกเป็นหัวตาราง ตัวหนา) */
function write_xlsx(array $rows, string $sheetName = 'Sheet1'): string {
  $name = xml_esc(preg_replace('/[\\\\\/?*\[\]:]/', '-', mb_substr($sheetName, 0, 31)));
  $width = 1;
  foreach ($rows as $r) $width = max($width, count($r));
  $body = '';
  foreach ($rows as $i => $r) {
    $body .= '<row r="' . ($i + 1) . '">';
    foreach (array_values($r) as $j => $v) {
      $ref = xlsx_col_name($j) . ($i + 1); $st = $i === 0 ? ' s="1"' : '';
      if (is_int($v) || is_float($v)) $body .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
      elseif ($v === '' || $v === null) continue;
      else $body .= '<c r="' . $ref . '"' . $st . ' t="inlineStr"><is><t xml:space="preserve">' . xml_esc($v) . '</t></is></c>';
    }
    $body .= '</row>';
  }
  $X = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
  return zip_pack([
    '[Content_Types].xml' => $X . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
    '_rels/.rels' => $X . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => $X . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => $X . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/styles.xml' => $X . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="16"/><name val="TH SarabunPSK"/></font><font><b/><sz val="16"/><name val="TH SarabunPSK"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
    'xl/worksheets/sheet1.xml' => $X . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="' . $width . '" width="20" customWidth="1"/></cols><sheetData>' . $body . '</sheetData></worksheet>',
  ]);
}

function xlsx_t_text(string $xml): string {
  $v = '';
  if (preg_match_all('/<t(?:\s[^>]*)?>([\s\S]*?)<\/t>/', $xml, $m)) $v = implode('', $m[1]);
  return xml_unesc($v);
}

/** อ่านไฟล์ .xlsx เป็น { ชื่อแผ่นงาน: [[...แถว]] } โดยค่าทุกช่องเป็นข้อความ */
function read_xlsx(string $buf): array {
  $f = zip_unpack($buf);
  $shared = [];
  if (preg_match_all('/<si>[\s\S]*?<\/si>/', $f['xl/sharedStrings.xml'] ?? '', $m)) foreach ($m[0] as $si) $shared[] = xlsx_t_text($si);
  $rels = [];
  if (preg_match_all('/<Relationship\b[^>]*>/', $f['xl/_rels/workbook.xml.rels'] ?? '', $m)) {
    foreach ($m[0] as $r) {
      if (preg_match('/Id="([^"]+)"/', $r, $id) && preg_match('/Target="([^"]+)"/', $r, $t)) $rels[$id[1]] = preg_replace('/^\//', '', preg_replace('/^\/?xl\//', '', $t[1]));
    }
  }
  $out = [];
  preg_match_all('/<sheet\b[^>]*>/', $f['xl/workbook.xml'] ?? '', $sheets);
  foreach ($sheets[0] as $s) {
    $name = preg_match('/name="([^"]*)"/', $s, $x) ? xml_unesc($x[1]) : '';
    $rid = preg_match('/r:id="([^"]+)"/', $s, $x) ? $x[1] : '';
    $xml = $f['xl/' . ($rels[$rid] ?? '')] ?? '';
    $rows = [];
    preg_match_all('/<row\b[^>]*?(?:\/>|>[\s\S]*?<\/row>)/', $xml, $rm);
    foreach ($rm[0] as $rx) {
      $rn = preg_match('/\br="(\d+)"/', $rx, $x) ? (int)$x[1] : count($rows) + 1;
      $row = [];
      preg_match_all('/<c\b[^>]*?(?:\/>|>[\s\S]*?<\/c>)/', $rx, $cm);
      foreach ($cm[0] as $cx) {
        $ref = preg_match('/\br="([A-Z]+\d+)"/', $cx, $x) ? $x[1] : null;
        $t = preg_match('/\bt="(\w+)"/', $cx, $x) ? $x[1] : 'n';
        if ($t === 'inlineStr') $v = xlsx_t_text($cx);
        else {
          $v = preg_match('/<v>([\s\S]*?)<\/v>/', $cx, $x) ? xml_unesc($x[1]) : '';
          if ($t === 's') $v = $shared[(int)$v] ?? '';
          elseif ($t === 'b') $v = $v === '1' ? 'true' : 'false';
        }
        $row[$ref ? xlsx_col_index($ref) : count($row)] = $v;
      }
      if ($row) { $w = max(array_keys($row)); for ($i = 0; $i <= $w; $i++) $row[$i] ??= ''; ksort($row); }
      $rows[$rn - 1] = $row;
    }
    if ($rows) { $w = max(array_keys($rows)); for ($i = 0; $i <= $w; $i++) $rows[$i] ??= []; ksort($rows); }
    $out[$name] = $rows;
  }
  return $out;
}
