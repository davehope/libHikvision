<?php
error_reporting(E_ALL);
date_default_timezone_set('GMT');

// This should be the path to your data directory, ending in a /.
$thumbnail_real = '/var/www/cameras/Thumbnails/CAM02_'; // path and start of file name.
$thumbnail_relative ='/cameras/Thumbnails/CAM02_';
$tmpPath = '/var/www/cameras/temp/'; # Path where temporary mp4 files can go

require_once 'libHikvision.php';

//$cfgCCTVPaths = array('/exports/CAM02/datadir0/','/exports/CAM02/datadir1');
$cfgCCTVPaths = "/mnt/cam02/info.bin";

/**
* Name: Preserve and update/rebuild query string<br>
* @param Example:
* Example URL: http://www.site.com/?category=foo&order=desc&page=2
*
* <a href="<?php echo queryString('order','asc'); ?>">Order ASC</a>
*
* Output HTML: <a href="?category=foo&amp;order=asc&amp;page=2">Order ASC</a>
* Output URL: http://www.site.com/?category=foo&order=asc&page=2
*
* Not http://www.site.com/?category=foo&order=desc&page=2&order=asc
*/
function queryString($str,$val)
{
	$queryString = array();
	$queryString = $_GET;
	$queryString[$str] = $val;
	$queryString = "?".htmlspecialchars(http_build_query($queryString),ENT_QUOTES);
	
	return $queryString;
}

$cctv = new hikvisionCCTV( $cfgCCTVPaths );

//
// Check query string to see if we need to download a file.
if(
	isset($_GET['datadir']) &&
	isset($_GET['file']) &&
	isset($_GET['start']) &&
	isset($_GET['end']) &&
	is_numeric($_GET['datadir']) &&
	is_numeric($_GET['file']) &&
	is_numeric($_GET['start']) &&
	is_numeric($_GET['end']) )
{
	$cctv->streamFileToBrowser(
		$cctv->extractSegmentMP4(
			$_GET['datadir'],$_GET['file'],$_GET['start'],$_GET['end'],$tmpPath
		)
	);
	exit();
}

//
// Determine period to view recordings for.
$filterDay = strtotime("midnight");
if( isset($_GET['Day']) && is_numeric($_GET['Day']) )
{
	$filterDay = $_GET['Day'];
}

$dayBegin = strtotime("3 days ago");
$dayEnd = strtotime("tomorrow");
// Need to check is valid date!
if( isset($_GET['SearchBegin']) && isset($_GET['SearchEnd'])) 
{
	$dayBegin = strtotime($_GET['SearchBegin']);
	$dayEnd = strtotime($_GET['SearchEnd']);
}

//
// Build array containing data.
$segmentsByDay = array();
$segments = $cctv->getSegmentsBetweenDates($dayBegin, $dayEnd );

foreach($segments as $segment)
{
	$startTime = $segment['cust_startTime'];
	$index = strtotime("midnight", $startTime);
	
	if(!isset( $segmentsByDay[$index] ))
	{
		$segmentsByDay[$index] = array(
			'start' => $index,
			'end' => strtotime("tomorrow", $startTime) - 1,
			'segments' => array()
			);
	}
	$segmentsByDay[$index]['segments'][] = $segment;
}

// =============================================================================
?>

<!doctype html>
<html>
<head>
<title>Example Hikvision Class</title>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
</head>
<body>
<style type="text/css">
body{font-family:'Bitstream Vera Sans','DejaVu Sans',Tahoma,sans-serif;font-size:13px;background-color:#e8e8e8}
.visualLabel{float:right;background-color:#e8e8e8;border-radius:10px;padding:0.3em;font-size:x-small}
form{margin-bottom:1em}
fieldset{margin:0;padding:0;padding-top:1em;border:0}
input[type="text"],input[type="password"],textarea,select{border:1px solid #E8E8E8;padding:5px;display:inline-block;width:320px;box-sizing:border-box}
select{width:332px}
a.button,input[type="submit"]{border:1px solid #D8D8D8;background:#F1F1F1;box-shadow:inset 0 1px 3px #fff,inset 0 -15px #E8E8E8,0 0 3px #E8E8E8;color:#000;text-shadow:0 1px #E8E8E8;padding:5px 30px;cursor:pointer}
a.button:hover,input[type="submit"]:hover{border:1px solid #DAAF00;background-color:#FFCC00;box-shadow:inset 0 1px 3px #fff,inset 0 -15px #DAAF00,0 0 3px #FFCC00;text-shadow:0 1px #DAAF00}
label{display:inline-block;width:120px;padding:5px;text-align:right}
table{width:100%;border-collapse:collapse;text-align:left;border-color:#E8E8E8;border:1px solid #e8e8e8;margin-bottom:1em}
thead th{color:#494949;font-size:1.0em;padding:8px;background-color:#F1F1F1;border-top:5px solid #E8E8E8}
tbody tr td,tfoot tr td,tfoot tr th{padding:9px 8px 8px;font-size:0.9em;background:#fff;white-space:nowrap}
td{border:1px solid #E8E8E8}
a.button{line-height:30px;padding:5px 10px}
.formField{margin:0 0 5px;display:block}

.cctvLive img{max-width:100%;height:auto}
.cctvImg{ position:relative;float:left;clear:none;overflow:hidden;width:320px;height:180px;margin:2px;max-width:100%}
.cctvImg img{position:relative;z-index:1}
.cctvImg p{display:block;position:absolute;width:100%;bottom:0;left:0;z-index:2;text-align:center;background-color:#494949;opacity:0.8;color:#fff;margin-bottom:3px;font-size:14px;padding:4px}
.cctvDay:after{display:block;content:' ';clear:both}
.cctvDay{display:none}

#LeftPanel{width:210px;float:left;margin-right: 15px}
#RightPanel{position:relative;margin-left:210px;padding-left:20px}
</style>
<script>
// playVideoWindow(0,88,176652800,221621788,'/cameras/Thumbnails/CAM02_0_88_176652800.jpg')
function playVideoWindow( _cust_dataDirNum, _cust_fileNum, _startOffset, _endOffset ,_posterUrl )
{
	var newWindow = window.open("", "_blank", "toolbar=no,scrollbar=no,resizable=yes,width=720,height=410");
	newWindow.document.write("<!DOCTYPE html><html><body style=\"margin:0\"><video controls width=\"100%\" poster=\""+ _posterUrl + "\">" +
		"<source src=\"?datadir="+ _cust_dataDirNum +"&amp;file="+_cust_fileNum+"&amp;start="+_startOffset+"&amp;end="+_endOffset+"\" type=\"video/mp4\"></video></body></html>");
}
</script>

<h1>CCTV Video Archive</h1>
 <div id="LeftPanel">
	<form method="get" action="<?php echo  $_SERVER['SCRIPT_NAME']; ?>">
		<fieldset>
			<div class="formField">
			<label for="SearchBegin" style="width:30px;display:inline-block">Begin</label>
			<input type="date" id="SearchBegin" name="SearchBegin" value="<?php echo date('Y-m-d', $dayBegin); ?>" />
			</div>
			<div class="formField">
			<label for="SearchEnd" style="width:30px;display:inline-block">End</label>
			<input type="date" id="SearchEnd" name="SearchEnd" value="<?php echo date('Y-m-d', $dayEnd); ?>" />
			</div>
			<label for="frmSubmit" style="width:30px">&nbsp;</label>
			<input type="submit" value="Search" id="frmSubmit">
		</fieldset>
	</form>
	<table>
		<thead>
			<tr><th>Date</th></tr>
		</thead>
		<tbody>
		<?php
		foreach($segmentsByDay as $day)
		{
			echo '<tr><td><a href="'.
					 $_SERVER['SCRIPT_NAME'].
					queryString('Day',$day['start']).
					'">'.
					date('l j F Y', $day['start']).'</a>'.
					'<span class="visualLabel">'.count($day['segments']).'</span></td></tr>';
		}
		?>
		</tbody>
	</table>	
&nbsp;
</div>

<div id="RightPanel">
<?php
if(isset($segmentsByDay[$filterDay]))
{	
	// Sort recordings in order of most recent.	
	$recordings = $segmentsByDay[$filterDay]['segments'];
	usort($recordings, function ($a, $b) {
		return strcmp( $b['cust_startTime'], $a['cust_startTime'] );
		});
	
	foreach($recordings as $recording)
	{
		$startTime = strftime('%H:%M:%S',$recording['cust_startTime']);
		$endTime = strftime('%H:%M:%S', $recording['cust_endTime']);
		
		$cctv->extractThumbnail(
			$recording['cust_dataDirNum'],
			$recording['cust_fileNum'],
			$recording['startOffset'],
			$thumbnail_real.$recording['cust_dataDirNum'].'_'.$recording['cust_fileNum'].'_'.$recording['startOffset'].'.jpg'
			);
		
		$thumbnail = $thumbnail_relative.$recording['cust_dataDirNum'].'_'.$recording['cust_fileNum'].'_'.$recording['startOffset'].'.jpg';
		echo '<div class="cctvImg">'.
				'<a href="#" onclick="playVideoWindow('.$recording['cust_dataDirNum'].','.$recording['cust_fileNum'].','.$recording['startOffset'].','.$recording['endOffset'].',\''.$thumbnail.'\')">'.
				'<img src="'.$thumbnail.'" width="320" height="180"/></a>'.
				'<p>'.$startTime.' to '. $endTime .'</p>'.
				'</div>';
	}
}
else
{
	echo '<p>No recordings to display</p>';
}
?>
<div style="clear:both;">&nbsp;</div>
</div>
</body>
</html>