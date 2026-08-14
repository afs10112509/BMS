<?php
$path = $argv[1];
$zip = new ZipArchive; $zip->open($path);
$shared=[]; $ss=$zip->getFromName('xl/sharedStrings.xml');
if($ss){$sx=simplexml_load_string($ss); foreach($sx->si as $si){ if(isset($si->t)) $shared[]=(string)$si->t; else { $t=''; foreach($si->r as $r)$t.=(string)$r->t; $shared[]=$t; }}}
$col=function($c){$n=0; foreach(str_split($c) as $ch)$n=$n*26+(ord($ch)-64); return $n;};
$xml=simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
$dates=[]; $raws=[];
foreach($xml->sheetData->row as $row){
  $cells=[];
  foreach($row->c as $c){ preg_match('/([A-Z]+)/',(string)$c['r'],$m); $ci=$col($m[1]); $t=(string)$c['t']; $v=isset($c->v)?(string)$c->v:''; if($t==='s')$v=$shared[(int)$v]??''; $cells[$ci]=$v; }
  $d=$cells[1]??''; if($d===''||stripos(implode('|',$cells),'tanggal')!==false) continue;
  $raws[$d]=($raws[$d]??0)+1;
}
arsort($raws);
echo "UNIQUE_DATE_RAW (top 40):\n";
$i=0; foreach($raws as $k=>$n){ echo json_encode($k)."|n=$n\n"; if(++$i>=40) break; }
echo "COUNT_UNIQUE=".count($raws)."\n";
